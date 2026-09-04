import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * ProfitabilityTab tests. Mirrors goods-inward-mode-card.test.tsx's
 * conventions: react-i18next mocked in selector mode (t($ => $.a.b) resolves
 * to the leaf key), and the feature's OWN hooks module
 * (use-finance-intelligence.ts) mocked directly rather than exercising real
 * React Query — these are component-rendering tests, not integration tests
 * against a mocked service layer.
 *
 * The product/channel cases are the load-bearing tests here: they assert the
 * HONEST "not yet available" card renders (ProfitabilityService::
 * byUntaggedDimension() returns `available:false` by design), and that it is
 * never confused with a plain empty-grid message.
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

const mockCompany = vi.hoisted(() => vi.fn());
const mockBranch = vi.hoisted(() => vi.fn());
const mockCostCenter = vi.hoisted(() => vi.fn());
const mockProject = vi.hoisted(() => vi.fn());
const mockCustomer = vi.hoisted(() => vi.fn());
const mockProduct = vi.hoisted(() => vi.fn());
const mockChannel = vi.hoisted(() => vi.fn());

vi.mock('../hooks/use-finance-intelligence', () => ({
  useProfitabilityCompany: mockCompany,
  useProfitabilityBranch: mockBranch,
  useProfitabilityCostCenter: mockCostCenter,
  useProfitabilityProject: mockProject,
  useProfitabilityCustomer: mockCustomer,
  useProfitabilityProduct: mockProduct,
  useProfitabilityChannel: mockChannel,
}));

import { ProfitabilityTab } from './profitability-tab';

const IDLE = { data: undefined, isLoading: false, isError: false };

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockCompany.mockReturnValue(IDLE);
  mockBranch.mockReturnValue(IDLE);
  mockCostCenter.mockReturnValue(IDLE);
  mockProject.mockReturnValue(IDLE);
  mockCustomer.mockReturnValue(IDLE);
  mockProduct.mockReturnValue(IDLE);
  mockChannel.mockReturnValue(IDLE);
});

describe('ProfitabilityTab', () => {
  it('renders company profitability figures exactly as the server returned them', () => {
    mockCompany.mockReturnValue({
      data: { dimension: 'company', revenue: 10000, expense: 6000, profit: 4000, margin_pct: 40 },
      isLoading: false,
      isError: false,
    });

    render(<ProfitabilityTab />);

    expect(screen.getByText('$10000')).toBeInTheDocument();
    expect(screen.getByText('$6000')).toBeInTheDocument();
    expect(screen.getByText('$4000')).toBeInTheDocument();
    expect(screen.getByText('40%')).toBeInTheDocument();
  });

  it('shows a loading state before the company endpoint responds', () => {
    mockCompany.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    render(<ProfitabilityTab />);
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('shows an error state when the company endpoint fails', () => {
    mockCompany.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    render(<ProfitabilityTab />);
    expect(screen.getByText('error')).toBeInTheDocument();
  });

  it('renders branch rows in a grid with the raw ledger dimension id shown verbatim', async () => {
    const user = userEvent.setup();
    mockBranch.mockReturnValue({
      data: {
        dimension: 'branch',
        rows: [
          { branch: 'b1111111-uuid', revenue: 500, expense: 200, profit: 300, margin_pct: 60 },
        ],
      },
      isLoading: false,
      isError: false,
    });

    render(<ProfitabilityTab />);
    await user.click(screen.getByText('branch'));

    expect(screen.getByText('b1111111-uuid')).toBeInTheDocument();
    expect(screen.getByText('$300')).toBeInTheDocument();
  });

  it('shows the HONEST "not yet available" state for product — never an empty chart', async () => {
    const user = userEvent.setup();
    mockProduct.mockReturnValue({
      data: {
        dimension: 'product',
        available: false,
        note: 'The ledger does not tag journal lines by product; company-level profitability is shown.',
        company: { dimension: 'company', revenue: 10000, expense: 6000, profit: 4000, margin_pct: 40 },
      },
      isLoading: false,
      isError: false,
    });

    render(<ProfitabilityTab />);
    await user.click(screen.getByText('product'));

    // The backend's own explanation is rendered verbatim.
    expect(screen.getByText(/does not tag journal lines by product/)).toBeInTheDocument();
    // The explicit heading distinguishes this from a merely-empty grid.
    expect(screen.getByText('heading')).toBeInTheDocument();
    // The company-level fallback figures are shown for context, not hidden.
    expect(screen.getByText('$10000')).toBeInTheDocument();
    // This is NOT the generic empty-grid message.
    expect(screen.queryByText('empty')).not.toBeInTheDocument();
  });

  it('shows the HONEST "not yet available" state for channel too', async () => {
    const user = userEvent.setup();
    mockChannel.mockReturnValue({
      data: {
        dimension: 'channel',
        available: false,
        note: 'The ledger does not tag journal lines by channel; company-level profitability is shown.',
        company: { dimension: 'company', revenue: 10000, expense: 6000, profit: 4000, margin_pct: 40 },
      },
      isLoading: false,
      isError: false,
    });

    render(<ProfitabilityTab />);
    await user.click(screen.getByText('channel'));

    expect(screen.getByText(/does not tag journal lines by channel/)).toBeInTheDocument();
    expect(screen.queryByText('empty')).not.toBeInTheDocument();
  });

  it('renders NoAccess instead of any profitability data without finance.analytics.view', () => {
    mockCan.mockReturnValue(false);
    mockCompany.mockReturnValue({
      data: { dimension: 'company', revenue: 10000, expense: 6000, profit: 4000, margin_pct: 40 },
      isLoading: false,
      isError: false,
    });

    render(<ProfitabilityTab />);

    expect(screen.getByText('noAccess')).toBeInTheDocument();
    expect(screen.queryByText('$10000')).not.toBeInTheDocument();
  });
});

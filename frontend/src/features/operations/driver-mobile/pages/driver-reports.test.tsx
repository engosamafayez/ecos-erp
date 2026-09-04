import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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
    t: (sel: unknown, opts?: Record<string, unknown>) => {
      const key = typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel);
      return opts ? `${key}:${Object.values(opts).join(',')}` : key;
    },
    i18n: { language: 'en', exists: () => true },
  }),
}));
vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }));
vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => String(n) }) }));

const walletData: { data: unknown; isLoading: boolean; isError: boolean } = { data: null, isLoading: false, isError: false };
const ordersData: { data: unknown; isLoading: boolean; isError: boolean } = { data: null, isLoading: false, isError: false };
const idleQuery = { data: null, isLoading: false, isError: false, refetch: vi.fn() };
vi.mock('../hooks/use-driver-mobile', () => ({
  useDriverWallet: () => ({ ...walletData, refetch: vi.fn() }),
  useDriverOrdersReport: () => ({ ...ordersData, refetch: vi.fn() }),
  useDriverGoodsMovement: () => idleQuery,
  useDriverShortages: () => idleQuery,
  useDriverAdvances: () => ({ data: { available: true, items: [], total: 0, pending_count: 0 }, isLoading: false, isError: false, refetch: vi.fn() }),
  useDriverExpenses: () => ({ data: { available: true, items: [], total: 0, pending_count: 0 }, isLoading: false, isError: false, refetch: vi.fn() }),
}));

import { DriverWalletPage } from './driver-wallet-page';
import { DriverReportsPage } from './driver-reports-page';

describe('Driver Wallet', () => {
  it('shows only the open position with canonical advances/expenses (§6/§7)', () => {
    walletData.data = {
      period: { from: '2026-08-01', to: '2026-08-31' },
      trips: 2,
      collections: { total: 500, cash: 300, transfer: 150, card: 50, already_paid: 0 },
      cash: { expected: 300, submitted: 290, difference: -10, is_balanced: false },
      settlement_status: 'under_review',
      advances: { available: true, items: [], total: 120, pending_count: 0 },
      expenses: { available: true, items: [], total: 80, pending_count: 0 },
      liability: { available: false },
      closing: {
        all_trips_closed: false, deliveries_outstanding: 1, custody_remaining: 2,
        custody_reconciled: false, settlement_status: 'under_review', settlement_complete: false,
      },
    };
    render(<DriverWalletPage />);

    expect(screen.getByText('500')).toBeInTheDocument(); // total collected, money-formatted
    expect(screen.getByText('wallet.collections')).toBeInTheDocument();
    // §7 — the wallet is labelled as the current open/unsettled position.
    expect(screen.getByText('wallet.openPositionHint')).toBeInTheDocument();
    // §6 — advances + expenses now come from the canonical movement ledger (no "unavailable" note).
    expect(screen.getByText('wallet.advances')).toBeInTheDocument();
    expect(screen.getByText('120')).toBeInTheDocument(); // advances total
    expect(screen.getByText('80')).toBeInTheDocument(); // expenses total
    expect(screen.queryByText('wallet.advancesUnavailable')).toBeNull();
  });
});

describe('Driver Reports', () => {
  it('renders the orders histogram on the default tab', () => {
    ordersData.data = {
      period: { from: '2026-08-01', to: '2026-08-31' },
      summary: { received: 3, delivered: 1, partial: 0, failed: 1, returned: 0, skipped: 0, pending: 1, deferred: 0, delivery_rate: 33 },
      items: [],
      meta: { current_page: 1, per_page: 20, total: 3, last_page: 1 },
    };
    render(<DriverReportsPage />);

    expect(screen.getByText('reports.title')).toBeInTheDocument();
    expect(screen.getByText('reports.orders.received')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument(); // received count
    expect(screen.getByText('33%')).toBeInTheDocument(); // delivery rate
    // The report tabs are exposed (§3/§6) — including Advances and the new Expenses tab.
    expect(screen.getByText('reports.tabs.advances')).toBeInTheDocument();
    expect(screen.getByText('reports.tabs.expenses')).toBeInTheDocument();
  });
});

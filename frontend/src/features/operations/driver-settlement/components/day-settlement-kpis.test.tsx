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
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    i18n: { language: 'en', exists: () => true },
  }),
}));
vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({ money: (v: number) => `EGP ${v.toFixed(2)}`, currency: 'EGP', number: (v: number) => String(v), percent: (v: number) => `${v}%` }),
}));

import { DaySettlementKpiCards } from './day-settlement-kpis';
import type { DaySettlementKpis } from '../types/driver-settlement';

function kpis(over: Partial<DaySettlementKpis> = {}): DaySettlementKpis {
  return {
    total_orders: 10,
    total_delivered: 7,
    total_failed: 3,
    delivery_rate: 70,
    total_sales: 13200,
    total_transfers_paid: 1500,
    total_expenses: null,
    net_cash: null,
    ...over,
  };
}

describe('DaySettlementKpiCards', () => {
  it('renders the 8 operational KPIs; Delivery Rate is a percentage only (no fraction)', () => {
    render(<DaySettlementKpiCards kpis={kpis()} />);
    expect(screen.getByText('driverSettlement.kpis.totalOrders')).toBeInTheDocument();
    expect(screen.getByText('driverSettlement.kpis.netCash')).toBeInTheDocument();
    expect(screen.getByText('10')).toBeInTheDocument(); // total orders
    expect(screen.getByText('7')).toBeInTheDocument(); // delivered
    expect(screen.getByText('70%')).toBeInTheDocument(); // delivery rate — percentage only
    expect(screen.queryByText('7/10')).not.toBeInTheDocument();
    expect(screen.getByText('EGP 13200.00')).toBeInTheDocument(); // total sales
  });

  it('shows Expenses and Net Cash as "Not available" (null) — never a fabricated zero (§10/§11/§12)', () => {
    render(<DaySettlementKpiCards kpis={kpis({ total_expenses: null, net_cash: null })} />);
    expect(screen.getAllByText('driverSettlement.notAvailable')).toHaveLength(2);
  });

  it('distinguishes a real canonical zero from "Not available" (§12)', () => {
    render(
      <DaySettlementKpiCards
        kpis={kpis({ total_orders: 0, total_delivered: 0, total_failed: 0, delivery_rate: 0, total_sales: 0, total_transfers_paid: 0 })}
      />,
    );
    expect(screen.getAllByText('EGP 0.00').length).toBeGreaterThanOrEqual(1); // real zero sales/transfers
    expect(screen.getByText('0%')).toBeInTheDocument(); // delivery rate 0%
    // Expenses / Net Cash remain "Not available" (null) — not zero.
    expect(screen.getAllByText('driverSettlement.notAvailable')).toHaveLength(2);
  });

  it('renders distinct loading and error states', () => {
    const { rerender } = render(<DaySettlementKpiCards loading />);
    expect(screen.getByTestId('kpi-loading')).toBeInTheDocument();

    rerender(<DaySettlementKpiCards error />);
    expect(screen.getByTestId('kpi-error')).toBeInTheDocument();
    expect(screen.queryByTestId('kpi-loading')).not.toBeInTheDocument();
  });
});

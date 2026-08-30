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
  useFormatter: () => ({
    money: (v: number) => `EGP ${v.toFixed(2)}`,
    currency: 'EGP',
    number: (v: number) => String(v),
    percent: (v: number) => `${v}%`,
  }),
}));

import { DriverCashPositionCards } from './driver-cash-position-cards';
import type { DaySettlementCollections } from '../types/driver-settlement';

const NA = 'driverSettlement.notAvailable';

function collections(over: Partial<DaySettlementCollections> = {}): DaySettlementCollections {
  return {
    cash: 6500,
    bank_transfer: 1200,
    card: 300,
    already_paid: 0,
    total_collected: 8000,
    delivered_sales: 8000,
    actual_collected: 8000,
    cash_expected: 6500,
    actual_cash: null,
    expected_collection: 8000,
    expected_collection_available: true,
    collection_difference: 0,
    ...over,
  };
}

describe('DriverCashPositionCards', () => {
  it('Cash Collected is physical cash only; electronic is shown separately and does not inflate it (§3/§9)', () => {
    render(
      <DriverCashPositionCards
        collections={collections({ cash: 6500, bank_transfer: 1200, card: 300 })}
        expenses={0}
        cashIn={0}
        netCash={6500}
      />,
    );
    // Cash Collected = 6500 exactly (NOT 6500 + 1500 electronic). Net Cash also 6500 here.
    expect(screen.getAllByText('EGP 6500.00')).toHaveLength(2);
    // Electronic (1200 + 300) surfaced separately.
    expect(screen.getByText('EGP 1500.00')).toBeInTheDocument();
  });

  it('Expenses / Cash In / Net Cash are CANONICAL approved movements — real, not "Not available" (§12/§13/§14)', () => {
    // 6500 cash + 1000 advance − 750 expenses = 6750 net.
    render(
      <DriverCashPositionCards
        collections={collections({ cash: 6500 })}
        expenses={750}
        cashIn={1000}
        netCash={6750}
      />,
    );
    expect(screen.getByText('EGP 750.00')).toBeInTheDocument();  // approved expenses (cash out)
    expect(screen.getByText('EGP 1000.00')).toBeInTheDocument(); // approved advances (cash in)
    expect(screen.getByText('EGP 6750.00')).toBeInTheDocument(); // net cash
  });

  it('Expected Collection shows the canonical snapshot when available and "Not available" otherwise (§2/§8)', () => {
    const { rerender } = render(
      // 7000 is distinct from the 8000 total-customer figure, so the assertion is unambiguous.
      <DriverCashPositionCards
        collections={collections({ expected_collection: 7000, expected_collection_available: true })}
        expenses={0}
        cashIn={0}
        netCash={6500}
      />,
    );
    expect(screen.getByText('EGP 7000.00')).toBeInTheDocument();

    rerender(
      <DriverCashPositionCards
        collections={collections({ expected_collection: null, expected_collection_available: false })}
        expenses={0}
        cashIn={0}
        netCash={6500}
      />,
    );
    // Only Expected Collection is unavailable now — Expenses / Net Cash are canonical.
    expect(screen.getAllByText(NA).length).toBeGreaterThanOrEqual(1);
  });

  it('distinguishes a canonical real zero (EGP 0.00) from "Not available" (§8)', () => {
    render(
      <DriverCashPositionCards
        collections={collections({
          cash: 0,
          bank_transfer: 0,
          card: 0,
          expected_collection: 0,
          expected_collection_available: true,
          collection_difference: 0,
        })}
        expenses={0}
        cashIn={0}
        netCash={0}
      />,
    );
    // Real zero renders as money, not as "Not available".
    expect(screen.getAllByText('EGP 0.00').length).toBeGreaterThanOrEqual(1);
  });
});

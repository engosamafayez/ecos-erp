import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n: resolve `t($ => $.a.b.c)` to the dotted path so assertions are stable.
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
  useFormatter: () => ({ money: (v: number) => `EGP ${v}`, currency: 'EGP', number: (v: number) => String(v), percent: (v: number) => `${v}%` }),
}));

import { DaySettlementDriverCard } from './day-settlement-driver-card';
import type { DaySettlementDriverRow } from '../types/driver-settlement';

const ROW: DaySettlementDriverRow = {
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

describe('DaySettlementDriverCard', () => {
  it('renders the Driver/Trip operational summary — order counts + values, delivery rate, financials (no vehicle/cash)', () => {
    render(<DaySettlementDriverCard row={ROW} onOpen={vi.fn()} />);
    expect(screen.getByRole('listitem')).toBeInTheDocument();
    expect(screen.getByText('Ahmed Samir')).toBeInTheDocument();
    expect(screen.getByText('TRP-012')).toBeInTheDocument(); // trip ref, not vehicle
    expect(screen.queryByText('ABC-123')).not.toBeInTheDocument(); // vehicle not a primary metric (§15)
    expect(screen.queryByText('17/18')).not.toBeInTheDocument(); // no delivered/total fraction (§32)
    expect(screen.getByText('94%')).toBeInTheDocument(); // delivery rate — percentage only
    expect(screen.getByText('EGP 18500')).toBeInTheDocument(); // total orders value
    expect(screen.getByText('EGP 4100')).toBeInTheDocument(); // exceptions value
    expect(screen.getByText('EGP 1500')).toBeInTheDocument(); // transfers / paid
  });

  it('shows Expenses and Net Cash as canonical values, never damage/shortage as primary metrics (§12/§14/§15)', () => {
    render(
      <DaySettlementDriverCard
        row={{ ...ROW, expenses: 750, net_cash: 6750, damaged_qty: 9, shortage_qty: 7 }}
        onOpen={vi.fn()}
      />,
    );
    // Expenses + Net Cash → real canonical figures (approved movements), not "Not available".
    expect(screen.getByText('EGP 750')).toBeInTheDocument();
    expect(screen.getByText('EGP 6750')).toBeInTheDocument();
    expect(screen.queryByText('driverSettlement.notAvailable')).not.toBeInTheDocument();
    // Damage / shortage are NOT primary mobile metrics — no chips even when non-zero.
    expect(screen.queryByLabelText('driverSettlement.columns.damaged: 9')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('driverSettlement.columns.shortage: 7')).not.toBeInTheDocument();
    // Goods Remaining stays visible.
    expect(screen.getByText('driverSettlement.columns.goodsRemaining')).toBeInTheDocument();
  });

  it('opens the canonical detail experience from the primary action', () => {
    const onOpen = vi.fn();
    render(<DaySettlementDriverCard row={ROW} onOpen={onOpen} />);
    fireEvent.click(screen.getByRole('button'));
    expect(onOpen).toHaveBeenCalledTimes(1);
    expect(onOpen).toHaveBeenCalledWith(ROW);
  });

  it('does not fabricate a missing driver name', () => {
    render(<DaySettlementDriverCard row={{ ...ROW, driver_name: null }} onOpen={vi.fn()} />);
    expect(screen.getByText('driverSettlement.unknownDriver')).toBeInTheDocument();
  });

  it('surfaces a duplicate open-custody warning only when flagged (§13)', () => {
    const { rerender } = render(<DaySettlementDriverCard row={ROW} onOpen={vi.fn()} />);
    expect(screen.queryByText('driverSettlement.duplicateCustody')).not.toBeInTheDocument();

    rerender(<DaySettlementDriverCard row={{ ...ROW, duplicate_open_custody: true }} onOpen={vi.fn()} />);
    expect(screen.getByText('driverSettlement.duplicateCustody')).toBeInTheDocument();
  });
});

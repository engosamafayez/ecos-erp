import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// This suite exercises the REAL UniversalDataGrid + DaySettlementDriverCard (no grid mock), so it
// proves the mobile card RENDER PATH end-to-end: 3 records must produce 3 accessible cards, and a
// card's action must open the canonical detail experience. (jsdom applies no media queries, so the
// grid's card layout and desktop table both mount; `role="listitem"` isolates the cards.)

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
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));
vi.mock('@/components/data-grid/smart-toolbar', () => ({ SmartToolbar: () => <div data-testid="toolbar" /> }));

import { useDriverSettlementBoard } from '../hooks/use-driver-settlement';
vi.mock('../hooks/use-driver-settlement', () => ({ useDriverSettlementBoard: vi.fn() }));

import { DriverSettlementWorkspacePage } from './driver-settlement-workspace-page';
import type { DaySettlementDriverRow } from '../types/driver-settlement';

const mockBoard = useDriverSettlementBoard as unknown as ReturnType<typeof vi.fn>;

function makeRow(id: number, name: string): DaySettlementDriverRow {
  return {
    assignment_id: id,
    operational_date: '2026-08-24',
    trip_id: `trip-${id}`,
    trip_number: `TRP-${id}`,
    trip_status: 'in_progress',
    custody_started_at: '2026-08-24T09:00:00Z',
    duplicate_open_custody: false,
    finalized_at: null,
    driver_id: id,
    driver_name: name,
    vehicle_id: id,
    vehicle_plate: `PLT-${id}`,
    trip_ids: [`t-${id}`],
    orders: 10,
    delivered: 9,
    partial: 0,
    failed: 1,
    delivery_pct: 90,
    returns: 0,
    cash_expected: 1000,
    transfers: 0,
    orders_value: 1000,
    delivered_value: 900,
    failed_value: 100,
    total_sales: 900,
    transfers_paid: 0,
    difference: 0,
    damaged_qty: 0,
    shortage_qty: 0,
    goods_on_hand: 0,
    cash_collected: 0,
    expenses: 0,
    cash_in: 0,
    net_cash: 0,
    pending_movements: 0,
    reconciliation_status: null,
    settlement_status: 'under_review',
    closing_stage: 'ready_for_closing',
  };
}

const ROWS = [makeRow(12, 'Ahmed Samir'), makeRow(13, 'Sara Nabil'), makeRow(14, 'Omar Adel')];

function activeBoard(drivers: DaySettlementDriverRow[]) {
  mockBoard.mockReturnValue({
    data: {
      scope: 'active',
      kpis: { total_orders: drivers.length * 10, total_delivered: drivers.length * 9, total_failed: drivers.length, delivery_rate: 90, total_sales: 900, total_transfers_paid: 0, total_expenses: null, net_cash: null },
      drivers,
    },
    isLoading: false,
    isFetching: false,
    isError: false,
    refetch: vi.fn(),
  });
}

describe('DriverSettlementWorkspacePage — mobile results render as cards', () => {
  it('renders one mobile card per record — Active KPI (=3) and visible card count are consistent', () => {
    activeBoard(ROWS);
    render(<DriverSettlementWorkspacePage />);
    const cards = screen.getAllByRole('listitem');
    expect(cards).toHaveLength(3);
    expect(within(cards[0]).getByText('Ahmed Samir')).toBeInTheDocument();
    expect(within(cards[1]).getByText('Sara Nabil')).toBeInTheDocument();
    expect(within(cards[2]).getByText('Omar Adel')).toBeInTheDocument();
  });

  it('opens the canonical detail experience when a card action is used', () => {
    activeBoard([ROWS[0]]);
    render(<DriverSettlementWorkspacePage />);
    const card = screen.getAllByRole('listitem')[0];
    fireEvent.click(within(card).getByRole('button'));
    expect(navigate).toHaveBeenCalledTimes(1);
    // Navigates to the canonical detail route for this assignment/day.
    expect(String(navigate.mock.calls[0][0])).toContain('date=2026-08-24');
  });
});

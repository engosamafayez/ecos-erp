import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { OperationalView } from './operational-view';
import type { KpiQueryState } from '../hooks/use-control-tower-kpis';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-002 §21.
 *
 * ┌─ WHAT THESE TESTS EXIST TO PROTECT ──────────────────────────────────────┐
 * │ Two things this task is explicitly about: (1) a tile with no real backend  │
 * │ count must NEVER render a fabricated number — status 'unavailable' means   │
 * │ no digit anywhere in the tile, not even a "0"; (2) every actionable tile    │
 * │ must navigate to the EXACT precise deep-link target named in the task's    │
 * │ own §11 examples (a `?tab=`/`?classification=` on the owning workspace),   │
 * │ never a generic module home. If either regresses, these tests fail.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown, vars?: Record<string, unknown>) => {
      if (typeof selector !== 'function') return String(selector);
      const path: string[] = [];
      const probe: unknown = new Proxy(
        {},
        { get(_t, prop): unknown { path.push(String(prop)); return probe; } },
      );
      (selector as (p: unknown) => unknown)(probe);
      const key = path[path.length - 1] ?? '';
      return vars === undefined ? key : [key, ...Object.values(vars).map(String)].join(' ');
    },
  }),
}));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
}));

vi.mock('./needs-attention-section', () => ({
  NeedsAttentionSection: () => <div data-testid="stub-needs-attention" />,
}));
vi.mock('./active-execution-section', () => ({
  ActiveExecutionSection: () => <div data-testid="stub-active-execution" />,
}));

const NULL_KPI: KpiQueryState = { isLoading: false, isError: false, value: null };
const kpiValue = (value: number): KpiQueryState => ({ isLoading: false, isError: false, value });

const mockKpis = vi.fn();
vi.mock('../hooks/use-control-tower-kpis', () => ({
  useControlTowerKpis: () => mockKpis(),
}));

function baseKpis() {
  return {
    readyForDistribution: kpiValue(4),
    loadingInProgress: kpiValue(2),
    readyForDriverHandover: kpiValue(1),
    tripsActive: kpiValue(6),
    deliveriesFailedToday: kpiValue(3),
    noAnswer: kpiValue(5),
    postponed: kpiValue(7),
    retriesRequired: kpiValue(12),
    driversAwaitingSettlement: kpiValue(2),
    alerts: { items: [], isLoading: false, isError: false },
    health: { data: null, isLoading: false, isError: false },
    loadingNeedsReview: { count: null, sessions: [], isLoading: false, isError: false },
  };
}

describe('OperationalView', () => {
  it('renders Needs Attention, Ready/Waiting, Active Execution, Failures and Returns & Settlement', () => {
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    expect(screen.getByTestId('stub-needs-attention')).toBeInTheDocument();
    expect(screen.getByTestId('control-tower-ready-waiting-grid')).toBeInTheDocument();
    expect(screen.getByTestId('stub-active-execution')).toBeInTheDocument();
    expect(screen.getByTestId('control-tower-failures-grid')).toBeInTheDocument();
    expect(screen.getByTestId('control-tower-returns-settlement-grid')).toBeInTheDocument();
  });

  it('renders a real value for a tile whose count is available', () => {
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    expect(screen.getByTestId('kpi-no-answer')).toHaveTextContent('5');
  });

  // §21.2 — the one that matters most.
  it('never renders a fabricated number for an unavailable tile', () => {
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    const groupsWaitingVehicle = screen.getByTestId('kpi-groups-waiting-vehicle');
    // No digit anywhere in the tile — not "0", not a placeholder count.
    expect(groupsWaitingVehicle.textContent ?? '').not.toMatch(/\d/);
  });

  it('shows no number, not a fabricated 0, when a real query returns null', () => {
    mockKpis.mockReturnValue({ ...baseKpis(), driversAwaitingSettlement: NULL_KPI });
    render(<OperationalView />);

    // NULL_KPI has isError: false, value: null — tileStatus() must classify
    // this as 'unavailable', not silently treat null as a zero.
    const tile = screen.getByTestId('kpi-drivers-awaiting-settlement');
    expect(tile.textContent ?? '').not.toMatch(/\d/);
  });

  // §21.6 / §21.7 — Distribution and Loading waiting states, §11's exact routing.
  it('routes Groups waiting for Vehicle/Driver to Dispatch & Execution Assignment tab', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-groups-waiting-vehicle'));
    expect(navigate).toHaveBeenCalledWith('/logistics/dispatch-execution?tab=assignment');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-groups-waiting-driver'));
    expect(navigate).toHaveBeenCalledWith('/logistics/dispatch-execution?tab=assignment');
  });

  it('routes Loading in Progress / Ready for Handover to their precise Dispatch & Execution tabs', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-loading-in-progress'));
    expect(navigate).toHaveBeenCalledWith('/logistics/dispatch-execution?tab=loading');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-ready-for-handover'));
    expect(navigate).toHaveBeenCalledWith('/logistics/dispatch-execution?tab=handover');
  });

  // §21.8 — Delivery failure / No Answer / Postponed mapping.
  it('routes each Failures/Exceptions tile to its exact Shipping Orders classification', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-deliveries-failed-today'));
    expect(navigate).toHaveBeenCalledWith('/operations/shipping-orders?classification=cancelled');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-no-answer'));
    expect(navigate).toHaveBeenCalledWith('/operations/shipping-orders?classification=no_answer');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-postponed'));
    expect(navigate).toHaveBeenCalledWith('/operations/shipping-orders?classification=postponed');
  });

  // §21.9 / §21.10 — Returns and Settlement/Treasury pending mapping.
  it('routes every Returns & Settlement tile to its exact tab on that workspace', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockKpis.mockReturnValue(baseKpis());
    render(<OperationalView />);

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-returns-expected'));
    expect(navigate).toHaveBeenCalledWith('/logistics/returns-settlement?tab=expected-returns');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-returns-awaiting-receipt'));
    expect(navigate).toHaveBeenCalledWith('/logistics/returns-settlement?tab=warehouse-receipt');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-drivers-awaiting-settlement'));
    expect(navigate).toHaveBeenCalledWith('/logistics/returns-settlement?tab=driver-settlement');

    navigate.mockClear();
    await userEvent.click(screen.getByTestId('kpi-cash-handover-pending'));
    expect(navigate).toHaveBeenCalledWith('/logistics/returns-settlement?tab=cash-handover');
  });

});

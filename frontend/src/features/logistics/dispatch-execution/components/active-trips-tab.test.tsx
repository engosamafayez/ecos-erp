import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { ActiveTripsTab } from './active-trips-tab';
import type { Trip } from '@/features/logistics/trips/types/trip';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §10/§21 — real stop progress (the SAME
 * `stops_completed_count`/`stops_count` fields Task 002 added and Control
 * Tower's own Active Execution section already shows — not a re-derived
 * computation here), a real exception marker (Trip's own already-fetched
 * `exceptions_count`, never a frontend-invented "attention" heuristic), and
 * precise deep links (§11 — Trips Workspace `?tripId=`, Shipping Orders
 * `?trip_id=`). The settlement banner (§17) must be real-count-driven, not a
 * decorative always-on link.
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

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'c-1' }),
}));

vi.mock('@/features/logistics/trips/components/trip-status-badge', () => ({
  TripStatusBadge: ({ status }: { status: string }) => <span data-testid="status-badge">{status}</span>,
}));

const mockOnTheRoad = vi.fn();
vi.mock('../hooks/use-on-the-road-trips', () => ({
  useOnTheRoadTrips: () => mockOnTheRoad(),
}));

const mockTripStats = vi.fn();
vi.mock('@/features/logistics/trips/hooks/use-trips', () => ({
  useTripStats: () => mockTripStats(),
}));

/**
 * `EntityTable` renders both a mobile-card view and a desktop-table view at
 * once (CSS-only toggle) — in JSDOM (no stylesheet loaded) both are
 * simultaneously "visible" to Testing Library, so every row's content
 * (including action buttons and their data-testid) genuinely appears twice.
 * These tolerate that duplication rather than assuming a single match.
 */
function expectPresent(text: string) {
  expect(screen.getAllByText(text).length).toBeGreaterThan(0);
}
function expectAbsent(matcher: string | RegExp) {
  expect(screen.queryAllByText(matcher)).toHaveLength(0);
}
function firstByTestId(testId: string): HTMLElement {
  return screen.getAllByTestId(testId)[0];
}

function trip(overrides: Partial<Trip> = {}): Trip {
  return {
    id: 'trip-1',
    trip_number: 'TRP-0001',
    driver: { id: 1, full_name: 'Ahmed Hassan' },
    vehicle: { id: 1, plate_number: 'ABC-123', label: null },
    orders_count: 5,
    stops_count: 5,
    stops_completed_count: 2,
    exceptions_count: 0,
    status: 'in_progress',
    ...overrides,
  } as Trip;
}

describe('ActiveTripsTab', () => {
  it('shows real completed/total stop progress, not just the total', () => {
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 0 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);

    expectPresent('2 / 5');
  });

  it('shows a real exception marker only when exceptions_count is greater than zero', () => {
    mockOnTheRoad.mockReturnValue({
      trips: [trip({ id: 'trip-2', exceptions_count: 2 })],
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 0 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);

    expectPresent('exceptions 2');
  });

  it('shows no exception marker at all when exceptions_count is zero', () => {
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 0 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);

    expectAbsent(/exceptions/);
  });

  it('deep-links "Open Trip" to the precise Trips Workspace tripId', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 0 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);
    await userEvent.click(firstByTestId('active-trip-open-trip-1'));

    expect(navigate).toHaveBeenCalledWith('/logistics/distribution/trips?tripId=trip-1');
  });

  it('deep-links "View Orders" to Shipping Orders filtered to this exact trip', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 0 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);
    await userEvent.click(firstByTestId('active-trip-orders-trip-1'));

    expect(navigate).toHaveBeenCalledWith('/operations/shipping-orders?trip_id=trip-1');
  });

  it('shows the settlement banner only when there is a real settlement_pending count', () => {
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 3 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);

    expect(screen.getByTestId('active-trips-open-settlement')).toBeInTheDocument();
    expect(screen.getByText('settlementPending 3')).toBeInTheDocument();
  });

  it('hides the settlement banner when settlement_pending is zero (no decorative always-on link)', () => {
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 0 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);

    expect(screen.queryByTestId('active-trips-open-settlement')).not.toBeInTheDocument();
  });

  it('deep-links the settlement banner to the precise Returns & Settlement tab', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    mockOnTheRoad.mockReturnValue({ trips: [trip()], isLoading: false, isError: false, refetch: vi.fn() });
    mockTripStats.mockReturnValue({ data: { settlement_pending: 1 }, isLoading: false, isError: false });

    render(<ActiveTripsTab />);
    await userEvent.click(screen.getByTestId('active-trips-open-settlement'));

    expect(navigate).toHaveBeenCalledWith('/logistics/returns-settlement?tab=driver-settlement');
  });
});

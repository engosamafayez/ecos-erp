import '@testing-library/jest-dom';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { TripLocationHistoryTab } from './trip-location-history-tab';
import type { RouteHistory } from '../types/live-map';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown, vars?: Record<string, unknown>) => {
      if (typeof selector !== 'function') return String(selector);
      const path: string[] = [];
      const probe: unknown = new Proxy({}, { get(_t, prop) { path.push(String(prop)); return probe; } });
      (selector as (p: unknown) => unknown)(probe);
      const key = path[path.length - 1] ?? '';
      return vars === undefined ? key : [key, ...Object.values(vars).map(String)].join(' ');
    },
    i18n: { language: 'en' },
  }),
}));

// Same rationale as live-driver-map-page.test.tsx: Leaflet's own rendering is
// not exercised at the unit level anywhere in this codebase.
vi.mock('./route-history-map', () => ({
  RouteHistoryMap: () => <div data-testid="route-history-map-stub" />,
}));

const mockRefetch = vi.hoisted(() => vi.fn());
const mockUseRouteHistory = vi.hoisted(() => vi.fn());
vi.mock('../hooks/use-live-map', () => ({
  useRouteHistory: () => mockUseRouteHistory(),
}));

function history(overrides: Partial<RouteHistory> = {}): RouteHistory {
  return {
    trip: {
      trip_id: 't-1',
      trip_number: 'TRP-001',
      status: 'completed',
      driver: { id: 1, full_name: 'Ahmed Ali', mobile: null },
      vehicle: { id: 1, plate_number: 'ABC-123', name: null },
      trip_started_at: '2026-09-01T08:00:00Z',
      trip_finished_at: '2026-09-01T12:00:00Z',
    },
    samples: [
      { lat: 30.1, lng: 31.2, recorded_at: '2026-09-01T08:00:00Z' },
      { lat: 30.2, lng: 31.3, recorded_at: '2026-09-01T08:05:00Z' },
    ],
    samples_truncated: false,
    stops: [
      { id: 's-1', sequence: 1, status: 'delivered', location: { lat: 30.15, lng: 31.25 }, completed_at: '2026-09-01T09:00:00Z' },
      { id: 's-2', sequence: 2, status: 'pending', location: null, completed_at: null },
    ],
    exceptions: [],
    ...overrides,
  };
}

describe('TripLocationHistoryTab', () => {
  it('shows a loading skeleton while the read is in flight', () => {
    mockUseRouteHistory.mockReturnValue({ data: undefined, isLoading: true, isError: false, refetch: mockRefetch });
    const { container } = render(<TripLocationHistoryTab tripId="t-1" />);

    expect(container.querySelector('[class*="skeleton"], [data-slot="skeleton"]')).toBeTruthy();
  });

  it('shows an error state with retry', () => {
    mockUseRouteHistory.mockReturnValue({ data: undefined, isLoading: false, isError: true, refetch: mockRefetch });
    render(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByText('error')).toBeInTheDocument();
    expect(screen.getByText('retry')).toBeInTheDocument();
  });

  it('honestly reports no recorded samples instead of an empty map', () => {
    mockUseRouteHistory.mockReturnValue({ data: history({ samples: [] }), isLoading: false, isError: false, refetch: mockRefetch });
    render(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByTestId('location-history-empty')).toBeInTheDocument();
    expect(screen.queryByTestId('route-history-map-stub')).not.toBeInTheDocument();
  });

  it('renders the map and replay controls once real samples exist', () => {
    mockUseRouteHistory.mockReturnValue({ data: history(), isLoading: false, isError: false, refetch: mockRefetch });
    render(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByTestId('route-history-map-stub')).toBeInTheDocument();
    expect(screen.getByTestId('route-replay-controls')).toBeInTheDocument();
  });

  it('shows the truncated note only when the backend reports truncation', () => {
    mockUseRouteHistory.mockReturnValue({
      data: history({ samples_truncated: true }),
      isLoading: false,
      isError: false,
      refetch: mockRefetch,
    });
    render(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByText(/truncatedNote/)).toBeInTheDocument();
  });

  it('lists a stop with no canonical coordinate as "No location", never a fake pin', () => {
    mockUseRouteHistory.mockReturnValue({ data: history(), isLoading: false, isError: false, refetch: mockRefetch });
    render(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByText('noLocation')).toBeInTheDocument();
  });

  it('renders every stop from canonical data, located or not', () => {
    mockUseRouteHistory.mockReturnValue({ data: history(), isLoading: false, isError: false, refetch: mockRefetch });
    render(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByText('#1')).toBeInTheDocument();
    expect(screen.getByText('#2')).toBeInTheDocument();
  });

  it('only shows the exceptions section when a real exception exists', () => {
    mockUseRouteHistory.mockReturnValue({ data: history(), isLoading: false, isError: false, refetch: mockRefetch });
    const { rerender } = render(<TripLocationHistoryTab tripId="t-1" />);
    expect(screen.queryByText('address_not_found')).not.toBeInTheDocument();

    mockUseRouteHistory.mockReturnValue({
      data: history({
        exceptions: [{ id: 1, stop_id: 's-1', exception_type: 'address_not_found', reported_at: '2026-09-01T09:30:00Z', resolved_at: null }],
      }),
      isLoading: false,
      isError: false,
      refetch: mockRefetch,
    });
    rerender(<TripLocationHistoryTab tripId="t-1" />);

    expect(screen.getByText('address_not_found')).toBeInTheDocument();
  });
});

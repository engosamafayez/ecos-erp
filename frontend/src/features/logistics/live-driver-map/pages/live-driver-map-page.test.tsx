import '@testing-library/jest-dom';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { LiveDriverMapPage } from './live-driver-map-page';
import type { LiveMapTrip } from '../types/live-map';

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

// Leaflet itself is not exercised at the unit level anywhere in this codebase
// (no existing test covers driver-stops-map.tsx or distribution-leaflet-map.tsx
// either) — this test covers the PAGE's own state machine (loading/error/empty/
// refetch), which is independent of the map's internal rendering.
vi.mock('../components/live-map-canvas', () => ({
  LiveMapCanvas: () => <div data-testid="live-map-canvas-stub" />,
}));

const mockRefetch = vi.hoisted(() => vi.fn());
const mockUseLiveMap = vi.hoisted(() => vi.fn());
vi.mock('../hooks/use-live-map', () => ({
  useLiveMap: () => mockUseLiveMap(),
}));

function trip(overrides: Partial<LiveMapTrip> = {}): LiveMapTrip {
  return {
    trip_id: 't-1',
    trip_number: 'TRP-001',
    status: 'out_for_delivery',
    driver: { id: 1, full_name: 'Ahmed Ali', mobile: null },
    vehicle: { id: 1, plate_number: 'ABC-123', name: null },
    location: { lat: 30.1, lng: 31.2, recorded_at: new Date().toISOString(), freshness: 'fresh' },
    stops_total: 5,
    stops_completed: 2,
    has_exception: false,
    ...overrides,
  };
}

describe('LiveDriverMapPage', () => {
  it('renders a loading state while the first read is in flight', () => {
    mockUseLiveMap.mockReturnValue({ data: undefined, isLoading: true, isError: false, isFetching: true, refetch: mockRefetch });
    render(<LiveDriverMapPage />);

    expect(screen.getByTestId('live-map-loading')).toBeInTheDocument();
  });

  it('renders an error state with retry, never a silently broken map', () => {
    mockUseLiveMap.mockReturnValue({ data: undefined, isLoading: false, isError: true, isFetching: false, refetch: mockRefetch });
    render(<LiveDriverMapPage />);

    expect(screen.getByText('error')).toBeInTheDocument();
    expect(screen.getByText('retry')).toBeInTheDocument();
  });

  it('renders an honest empty state when nothing is currently trackable, not an empty map', () => {
    mockUseLiveMap.mockReturnValue({ data: [], isLoading: false, isError: false, isFetching: false, refetch: mockRefetch });
    render(<LiveDriverMapPage />);

    expect(screen.getByTestId('live-map-empty')).toBeInTheDocument();
  });

  it('renders the map and driver list once trackable trips exist', () => {
    mockUseLiveMap.mockReturnValue({
      data: [trip({ trip_id: 't-1' }), trip({ trip_id: 't-2' })],
      isLoading: false,
      isError: false,
      isFetching: false,
      refetch: mockRefetch,
    });
    render(<LiveDriverMapPage />);

    expect(screen.getByTestId('live-map-canvas-stub')).toBeInTheDocument();
    expect(screen.getAllByTestId('live-map-row-t-1')[0]).toBeInTheDocument();
    expect(screen.getAllByTestId('live-map-row-t-2')[0]).toBeInTheDocument();
  });
});

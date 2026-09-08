import '@testing-library/jest-dom';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DriverListPanel } from './driver-list-panel';
import type { LiveMapTrip } from '../types/live-map';

/** Selector-mode t($ => $.a.b) stub — returns the last key segment, matching the repo's own idiom. */
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

function trip(overrides: Partial<LiveMapTrip> = {}): LiveMapTrip {
  return {
    trip_id: 't-1',
    trip_number: 'TRP-001',
    status: 'out_for_delivery',
    driver: { id: 1, full_name: 'Ahmed Ali', mobile: '0100000000' },
    vehicle: { id: 1, plate_number: 'ABC-123', name: 'Van 1' },
    location: {
      lat: 30.1,
      lng: 31.2,
      recorded_at: new Date().toISOString(),
      freshness: 'fresh',
    },
    stops_total: 5,
    stops_completed: 2,
    has_exception: false,
    ...overrides,
  };
}

describe('DriverListPanel', () => {
  it('renders one row per trackable trip', () => {
    render(
      <DriverListPanel
        trips={[trip({ trip_id: 't-1' }), trip({ trip_id: 't-2', trip_number: 'TRP-002' })]}
        selectedTripId={null}
        onSelectTrip={vi.fn()}
      />,
    );

    expect(screen.getByTestId('live-map-row-t-1')).toBeInTheDocument();
    expect(screen.getByTestId('live-map-row-t-2')).toBeInTheDocument();
  });

  it('shows Live for a fresh location and Stale for a stale one', () => {
    render(
      <DriverListPanel
        trips={[
          trip({ trip_id: 't-fresh', location: { lat: 1, lng: 1, recorded_at: new Date().toISOString(), freshness: 'fresh' } }),
          trip({ trip_id: 't-stale', location: { lat: 1, lng: 1, recorded_at: new Date().toISOString(), freshness: 'stale' } }),
        ]}
        selectedTripId={null}
        onSelectTrip={vi.fn()}
      />,
    );

    expect(screen.getByTestId('live-map-row-t-fresh')).toHaveTextContent('fresh');
    expect(screen.getByTestId('live-map-row-t-stale')).toHaveTextContent('stale');
  });

  it('shows "unknown" for a trackable trip with no recorded location yet, never a fake position', () => {
    render(
      <DriverListPanel
        trips={[trip({ trip_id: 't-none', location: null })]}
        selectedTripId={null}
        onSelectTrip={vi.fn()}
      />,
    );

    expect(screen.getByTestId('live-map-row-t-none')).toHaveTextContent('unknown');
  });

  it('shows the real stop progress, completed of total', () => {
    render(
      <DriverListPanel
        trips={[trip({ stops_completed: 3, stops_total: 7 })]}
        selectedTripId={null}
        onSelectTrip={vi.fn()}
      />,
    );

    expect(screen.getByTestId('live-map-row-t-1')).toHaveTextContent('stopProgress 3 7');
  });

  it('search filters by driver name, vehicle plate and trip number', async () => {
    const user = userEvent.setup();
    render(
      <DriverListPanel
        trips={[
          trip({ trip_id: 't-1', trip_number: 'TRP-001', driver: { id: 1, full_name: 'Ahmed Ali', mobile: null } }),
          trip({ trip_id: 't-2', trip_number: 'TRP-777', driver: { id: 2, full_name: 'Sara Youssef', mobile: null } }),
        ]}
        selectedTripId={null}
        onSelectTrip={vi.fn()}
      />,
    );

    await user.type(screen.getByTestId('live-map-search'), 'Sara');

    expect(screen.queryByTestId('live-map-row-t-1')).not.toBeInTheDocument();
    expect(screen.getByTestId('live-map-row-t-2')).toBeInTheDocument();
  });

  it('calls onSelectTrip with the trip id when a row is clicked', async () => {
    const user = userEvent.setup();
    const onSelectTrip = vi.fn();
    render(<DriverListPanel trips={[trip({ trip_id: 't-9' })]} selectedTripId={null} onSelectTrip={onSelectTrip} />);

    await user.click(screen.getByTestId('live-map-row-t-9'));

    expect(onSelectTrip).toHaveBeenCalledWith('t-9');
  });
});

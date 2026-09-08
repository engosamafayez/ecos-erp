import '@testing-library/jest-dom';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DriverDetailPanel } from './driver-detail-panel';
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

const mockNavigate = vi.hoisted(() => vi.fn());
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => mockNavigate };
});

const TRIP: LiveMapTrip = {
  trip_id: 'trip-uuid-1',
  trip_number: 'TRP-001',
  status: 'out_for_delivery',
  driver: { id: 1, full_name: 'Ahmed Ali', mobile: '0100000000' },
  vehicle: { id: 1, plate_number: 'ABC-123', name: 'Van 1' },
  location: { lat: 30.1, lng: 31.2, recorded_at: new Date().toISOString(), freshness: 'fresh' },
  stops_total: 5,
  stops_completed: 2,
  has_exception: false,
};

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §9 — deep links must reuse the EXACT
 * Task 003 URL vocabulary (`?tripId=` on the Trips Workspace, `?trip_id=` on
 * Shipping Orders) — a drifted param name silently breaks the link.
 */
describe('DriverDetailPanel', () => {
  it('renders the driver, vehicle and exception state', () => {
    render(<DriverDetailPanel trip={{ ...TRIP, has_exception: true }} />);

    expect(screen.getByText('Ahmed Ali')).toBeInTheDocument();
    expect(screen.getByText(/ABC-123/)).toBeInTheDocument();
    expect(screen.getByText('hasException')).toBeInTheDocument();
  });

  it('Open Trip navigates to the Trips Workspace with ?tripId=', async () => {
    const user = userEvent.setup();
    render(<DriverDetailPanel trip={TRIP} />);

    await user.click(screen.getByTestId('live-map-open-trip'));

    expect(mockNavigate).toHaveBeenCalledWith(expect.stringMatching(/\?tripId=trip-uuid-1$/));
  });

  it('Route History navigates to the Trips Workspace with ?tripId= and &tab=location-history', async () => {
    const user = userEvent.setup();
    render(<DriverDetailPanel trip={TRIP} />);

    await user.click(screen.getByTestId('live-map-route-history'));

    expect(mockNavigate).toHaveBeenCalledWith(
      expect.stringMatching(/\?tripId=trip-uuid-1&tab=location-history$/),
    );
  });

  it('Open in Shipping Orders navigates with ?trip_id= (the Shipping Orders filter param, not ?tripId=)', async () => {
    const user = userEvent.setup();
    render(<DriverDetailPanel trip={TRIP} />);

    await user.click(screen.getByTestId('live-map-open-shipping-orders'));

    expect(mockNavigate).toHaveBeenCalledWith(expect.stringMatching(/\?trip_id=trip-uuid-1$/));
  });

  it('shows Unknown, never a fake timestamp, when no location has been recorded', () => {
    render(<DriverDetailPanel trip={{ ...TRIP, location: null }} />);

    expect(screen.getByText('unknown')).toBeInTheDocument();
  });
});

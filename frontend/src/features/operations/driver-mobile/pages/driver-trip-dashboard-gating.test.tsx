import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DriverLoadingItem, DriverLoadingManifest, DriverTrip } from '../types/driver-mobile';

// Resolve the typed `t($ => $.a.b)` selector to its dotted key; append interpolation values so
// count/confirmed rendering can be asserted. Same idiom as driver-home-page.test.tsx.
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
    t: (sel: unknown, opts?: Record<string, unknown>) => {
      const key = typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel);
      return opts ? `${key}:${Object.values(opts).join(',')}` : key;
    },
    i18n: { language: 'en', exists: () => true },
  }),
}));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useParams: () => ({ tripId: 't-1' }), useNavigate: () => navigate }));

const tripHolder: { data: unknown; isLoading: boolean; isError: boolean; refetch: () => void } = {
  data: undefined, isLoading: false, isError: false, refetch: vi.fn(),
};
const manifestHolder: { data: unknown } = { data: null };
const idleMutation = { isPending: false, isError: false, error: null, mutate: vi.fn() };

vi.mock('../hooks/use-driver-mobile', () => ({
  useDriverTrip: () => tripHolder,
  useDriverLoading: () => manifestHolder,
  useStartTrip: () => idleMutation,
  useFinishTrip: () => idleMutation,
}));

// The real trip-lifecycle predicates (ON_THE_ROAD / UNRESOLVED_LOADING / …) are exercised.
import { DriverTripDashboardPage } from './driver-trip-dashboard-page';

function makeTrip(over: Partial<DriverTrip> = {}): DriverTrip {
  return {
    id: 't-1', trip_number: 'TRP-003', status: 'loading', company_id: 1, driver_id: 7,
    vehicle_id: 3, vehicle_plate: 'ABC-123', vehicle_name: 'Van', stops_count: 0,
    exceptions_count: 0, trip_started_at: null, trip_finished_at: null, ...over,
  };
}
function item(over: Partial<DriverLoadingItem> = {}): DriverLoadingItem {
  return {
    product_id: 'p-1', product_name: 'Honey', quantity_required: 10, quantity_prepared: 10,
    quantity_loaded: 1, quantity_remaining: 0, status: 'loaded', loading_task_id: 'lt-1',
    warehouse_confirmed_at: null, quantity_driver_received: 1, driver_confirmed_at: '2026-08-26T00:00:00Z',
    difference: 0, workflow_state: 'driver_confirmed', open_adjustment: null, ...over,
  };
}
function manifest(items: DriverLoadingItem[], loadingComplete = false): DriverLoadingManifest {
  return { shipment: { driver_name: 'D', orders_count: items.length, loading_complete: loadingComplete }, items };
}

function setup(trip: DriverTrip | undefined, m: DriverLoadingManifest | null, over: Partial<typeof tripHolder> = {}) {
  tripHolder.data = trip; tripHolder.isLoading = false; tripHolder.isError = false; tripHolder.refetch = vi.fn();
  Object.assign(tripHolder, over);
  manifestHolder.data = m;
}

const START = 'dashboard.startTrip';
const READY_HINT = 'dashboard.ready.hint';
const BLOCKER_TITLE = 'dashboard.blocker.title';
const GO_LOADING = 'dashboard.goToLoading';
const VIEW_STOPS = 'dashboard.viewStops';

describe('DriverTripDashboardPage — Start Trip workspace', () => {
  it('LOADING trip with a pending confirmation → non-empty workspace, blocker, NO Start Trip', () => {
    setup(makeTrip({ status: 'loading' }), manifest([item({ workflow_state: 'awaiting_driver_confirmation' })]));
    render(<DriverTripDashboardPage />);
    expect(screen.getByText('dashboard.readiness.title')).toBeInTheDocument(); // workspace not blank
    expect(screen.getByText(BLOCKER_TITLE)).toBeInTheDocument();
    expect(screen.getByText(/dashboard\.blocker\.awaiting:/)).toBeInTheDocument();
    expect(screen.getByText(GO_LOADING)).toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
  });

  it('LOADING trip, all confirmed but stranded (loading_complete flag, status still loading) → awaiting-dispatch blocker, NO Start Trip', () => {
    setup(makeTrip({ status: 'loading' }), manifest([item()], true)); // item confirmed, flag true, trip=loading
    render(<DriverTripDashboardPage />);
    expect(screen.getByText('dashboard.blocker.awaitingDispatch')).toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
    expect(screen.getByText(GO_LOADING)).toBeInTheDocument();
  });

  // VNEXT §16/§22: the separate Start Trip CTA is removed from Trip Detail. When ready, the page
  // shows the ready hint and routes to the Loading workspace ("Continue to Delivery"), where the
  // single "Ready to Start Delivery" departure action lives.
  it('LOADING_COMPLETED + nothing awaiting → ready hint + Continue to Delivery, NO Start Trip button, no blocker', () => {
    setup(makeTrip({ status: 'loading_completed', stops_count: 3 }), manifest([item()], true));
    render(<DriverTripDashboardPage />);
    expect(screen.getByText(READY_HINT)).toBeInTheDocument();
    expect(screen.getByText('dashboard.goToDeparture')).toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
    expect(screen.queryByText(BLOCKER_TITLE)).not.toBeInTheDocument();
  });

  it('§8 precedence: LOADING_COMPLETED but an item still awaits → NO Start Trip, awaiting blocker', () => {
    setup(makeTrip({ status: 'loading_completed' }), manifest([item({ workflow_state: 'awaiting_driver_confirmation' })], true));
    render(<DriverTripDashboardPage />);
    expect(screen.queryByText(START)).not.toBeInTheDocument();
    expect(screen.getByText(BLOCKER_TITLE)).toBeInTheDocument();
  });

  it('ON THE ROAD (in_progress) → View Stops + Finish, no Start Trip', () => {
    setup(makeTrip({ status: 'in_progress' }), null);
    render(<DriverTripDashboardPage />);
    expect(screen.getByText(VIEW_STOPS)).toBeInTheDocument();
    expect(screen.getByText('dashboard.finishTrip')).toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
  });

  it('ON THE ROAD (dispatched) → View Stops exposed (not just in_progress)', () => {
    setup(makeTrip({ status: 'dispatched' }), null);
    render(<DriverTripDashboardPage />);
    expect(screen.getByText(VIEW_STOPS)).toBeInTheDocument();
  });

  it('CLOSED → read-only closed summary, no action', () => {
    setup(makeTrip({ status: 'closed' }), null);
    render(<DriverTripDashboardPage />);
    expect(screen.getByText('dashboard.closed.title')).toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
    expect(screen.queryByText(VIEW_STOPS)).not.toBeInTheDocument();
  });

  it('DISPATCH_BLOCKED → blocked message, no Start Trip', () => {
    setup(makeTrip({ status: 'dispatch_blocked' }), null);
    render(<DriverTripDashboardPage />);
    expect(screen.getByText('dashboard.blocked.title')).toBeInTheDocument();
    expect(screen.getByText('dashboard.blocked.dispatchBlocked')).toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
  });

  it('read error → error + retry, never a blank body', () => {
    setup(undefined, null, { isError: true, data: undefined });
    render(<DriverTripDashboardPage />);
    expect(screen.getByText('dashboard.error')).toBeInTheDocument();
    expect(screen.getByText('dashboard.retry')).toBeInTheDocument();
  });
});

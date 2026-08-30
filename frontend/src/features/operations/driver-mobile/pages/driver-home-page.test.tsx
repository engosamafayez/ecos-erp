import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n: resolve `t($ => $.a.b)` to the dotted path, and expose
// interpolation values so identity/count rendering can be asserted.
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
    i18n: { language: 'ar', exists: () => true },
  }),
}));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));

const authState = { user: { name: 'Ahmed Samir' } as { name: string } | null };
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (sel: (s: typeof authState) => unknown) => sel(authState),
}));

vi.mock('../hooks/use-driver-mobile', () => ({
  useDriverTrips: vi.fn(),
  useDriverLoading: vi.fn(),
  useDriverStops: vi.fn(),
  useVehicleInventory: vi.fn(),
  useTripSettlement: vi.fn(),
}));

// The command-center Collections card reads the shared money formatter.
vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => String(n) }) }));

import {
  useDriverLoading,
  useDriverStops,
  useDriverTrips,
  useTripSettlement,
  useVehicleInventory,
} from '../hooks/use-driver-mobile';
import { DriverHomePage } from './driver-home-page';
import type { DriverLoadingItem, DriverLoadingManifest, DriverTrip } from '../types/driver-mobile';

const mTrips = useDriverTrips as unknown as ReturnType<typeof vi.fn>;
const mLoading = useDriverLoading as unknown as ReturnType<typeof vi.fn>;
const mStops = useDriverStops as unknown as ReturnType<typeof vi.fn>;
const mInv = useVehicleInventory as unknown as ReturnType<typeof vi.fn>;
const mSettle = useTripSettlement as unknown as ReturnType<typeof vi.fn>;

function trip(over: Partial<DriverTrip> = {}): DriverTrip {
  return {
    id: 't-1',
    trip_number: 'TRIP-1',
    status: 'loading',
    company_id: 1,
    driver_id: 7,
    vehicle_id: 3,
    vehicle_plate: '1336',
    vehicle_name: 'Van',
    stops_count: 0,
    exceptions_count: 0,
    trip_started_at: null,
    trip_finished_at: null,
    ...over,
  };
}

function item(over: Partial<DriverLoadingItem> = {}): DriverLoadingItem {
  return {
    product_id: 'p-1',
    product_name: 'Honey',
    quantity_required: 10,
    quantity_prepared: 10,
    quantity_loaded: 0,
    quantity_remaining: 10,
    status: 'pending',
    loading_task_id: null,
    warehouse_confirmed_at: null,
    quantity_driver_received: null,
    driver_confirmed_at: null,
    difference: null,
    workflow_state: 'pending_loading',
    open_adjustment: null,
    ...over,
  };
}

function manifest(items: DriverLoadingItem[], loadingComplete = false): DriverLoadingManifest {
  return { shipment: { driver_name: 'Ahmed', orders_count: 2, loading_complete: loadingComplete }, items };
}

function setup(opts: {
  trips?: DriverTrip[];
  manifest?: DriverLoadingManifest | null;
  stops?: unknown[];
  isError?: boolean;
  isLoading?: boolean;
} = {}) {
  mTrips.mockReturnValue({
    data: opts.trips,
    isLoading: opts.isLoading ?? false,
    isError: opts.isError ?? false,
    isFetching: false,
    refetch: vi.fn(),
  });
  mLoading.mockReturnValue({ data: opts.manifest ?? null });
  mStops.mockReturnValue({ data: opts.stops ?? [] });
  mInv.mockReturnValue({ data: { summary: { total_quantity_on_hand: 6, total_quantity_loaded: 10, total_quantity_delivered: 0, total_quantity_returned: 0, products_count: 2 }, items: [] } });
  mSettle.mockReturnValue({ data: undefined });
}

describe('DriverHomePage (operational)', () => {
  it('shows driver identity: name, vehicle plate and trip number', () => {
    setup({ trips: [trip()], manifest: manifest([item()]) });
    render(<DriverHomePage />);
    expect(screen.getByText('Ahmed Samir')).toBeInTheDocument();
    expect(screen.getByText('1336')).toBeInTheDocument();
    expect(screen.getByText('TRIP-1')).toBeInTheDocument();
  });

  it('LOADING, nothing loaded → current work = loading, next action = start loading', () => {
    setup({ trips: [trip({ status: 'loading' })], manifest: manifest([item({ quantity_loaded: 0 })]) });
    render(<DriverHomePage />);
    expect(screen.getByText('home.currentWork.loading')).toBeInTheDocument();
    expect(screen.getByText('home.nextAction.startLoading')).toBeInTheDocument();
    fireEvent.click(screen.getByText('home.nextAction.startLoading'));
    expect(navigate).toHaveBeenCalledWith('/driver/loading');
  });

  it('warehouse-loaded but unconfirmed → next action = confirm received', () => {
    setup({
      trips: [trip({ status: 'loading' })],
      manifest: manifest([item({ quantity_loaded: 5, workflow_state: 'awaiting_driver_confirmation' })]),
    });
    render(<DriverHomePage />);
    expect(screen.getByText('home.nextAction.confirmReceived')).toBeInTheDocument();
  });

  // TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §16/§21: when every loaded line is confirmed, the
  // next action is READY TO START DELIVERY, routed to the LOADING workspace (the single departure
  // path) — NOT a separate Start-Trip screen. Orders are worked after departure.
  it('loading complete + all confirmed → ready, next action = ready to start delivery (→ loading)', () => {
    setup({
      trips: [trip({ status: 'loading_completed' })],
      manifest: manifest([item({ quantity_loaded: 10, workflow_state: 'driver_confirmed' })], true),
    });
    render(<DriverHomePage />);
    expect(screen.getByText('home.currentWork.readyForDelivery')).toBeInTheDocument();
    fireEvent.click(screen.getByText('home.nextAction.readyToStartDelivery'));
    expect(navigate).toHaveBeenCalledWith('/driver/loading');
    // The separate Start-Trip journey is superseded.
    expect(screen.queryByText('home.nextAction.startTrip')).not.toBeInTheDocument();
  });

  // THE PHASE-2 DEFECT, pinned: a shipment flagged loading_complete while an item still
  // awaits this driver must NOT read as "ready". Pending confirmations take precedence
  // (§7/§22), so the next action is Confirm Received, not Start Trip.
  it('loading complete flag set BUT an item still awaits → next action = confirm received', () => {
    setup({
      trips: [trip({ status: 'loading_completed' })],
      manifest: manifest([item({ quantity_loaded: 10, workflow_state: 'awaiting_driver_confirmation' })], true),
    });
    render(<DriverHomePage />);
    expect(screen.getByText('home.nextAction.confirmReceived')).toBeInTheDocument();
    expect(screen.queryByText('home.nextAction.startTrip')).not.toBeInTheDocument();
  });

  // VNEXT §16/§21: the separate "Start Trip" journey no longer exists on Home — departure is the
  // "Ready to Start Delivery" action on the Loading workspace. Home always routes the ready state
  // to Loading (which owns the honest departure gate, including the stranded case: a shipment whose
  // assignment completed but whose trip never advanced past `loading`). Home never offers Start Trip.
  it('ready state routes to Loading, and no separate Start-Trip action exists', () => {
    setup({
      trips: [trip({ status: 'loading' })],
      manifest: manifest([item({ quantity_loaded: 10, workflow_state: 'driver_confirmed' })], true),
    });
    render(<DriverHomePage />);
    expect(screen.queryByText('home.nextAction.startTrip')).not.toBeInTheDocument();
    fireEvent.click(screen.getByText('home.nextAction.readyToStartDelivery'));
    expect(navigate).toHaveBeenCalledWith('/driver/loading');
  });

  // COMMAND CENTER (§3/§11/§27): the Daily Journey is the spine, shown for any active trip.
  it('renders the Daily Journey for an active trip', () => {
    setup({ trips: [trip({ status: 'loading' })], manifest: manifest([item({ quantity_loaded: 5, workflow_state: 'awaiting_driver_confirmation' })]) });
    render(<DriverHomePage />);
    expect(screen.getByText('home.journey.title')).toBeInTheDocument();
  });

  // Contextual: the Orders summary is NOT shown during loading (no deliveries yet)…
  it('does NOT show the orders summary during loading', () => {
    setup({ trips: [trip({ status: 'loading' })], manifest: manifest([item({ quantity_loaded: 5, workflow_state: 'awaiting_driver_confirmation' })]) });
    render(<DriverHomePage />);
    expect(screen.queryByText('home.orders.title')).not.toBeInTheDocument();
  });

  // …and IS shown once the trip is on the road, with the received/delivered distinction.
  it('shows the orders summary + next order once on the road', () => {
    const stops = [
      { id: 's1', sequence: 1, status: 'delivered', delivery_type: null, collected_amount: 0, payment_method: null, attempted_at: null, completed_at: null, notes: null,
        order: { id: 1, order_number: 'ORD-1', customer_name: 'A', phone: null, address: null, governorate: 'Cairo', city: null, area: null, gps: null, payment_method: null, grand_total: 100, deposit_paid: 0, remaining_balance: 100, items_count: 1, delivery_notes: null } },
      { id: 's2', sequence: 2, status: 'pending', delivery_type: null, collected_amount: 0, payment_method: null, attempted_at: null, completed_at: null, notes: null,
        order: { id: 2, order_number: 'ORD-2', customer_name: 'B', phone: null, address: null, governorate: 'Maadi', city: null, area: null, gps: null, payment_method: null, grand_total: 100, deposit_paid: 0, remaining_balance: 100, items_count: 1, delivery_notes: null } },
    ];
    setup({ trips: [trip({ status: 'in_progress' })], manifest: manifest([item()], true), stops });
    render(<DriverHomePage />);
    expect(screen.getByText('home.orders.title')).toBeInTheDocument();
    expect(screen.getByText('home.next.title')).toBeInTheDocument();
    expect(screen.getByText('ORD-2')).toBeInTheDocument(); // next = lowest-sequence open stop
  });

  it('on the road → in delivery, next action = next stop', () => {
    setup({ trips: [trip({ status: 'out_for_delivery' })], manifest: manifest([item()], true), stops: [] });
    render(<DriverHomePage />);
    expect(screen.getByText('home.currentWork.inDelivery')).toBeInTheDocument();
    expect(screen.getByText('home.nextAction.nextStop')).toBeInTheDocument();
  });

  /*
   * END OF DAY — Part 4 STATE E. `settlement_pending` used to collapse into
   * `completed`, which told the driver the day was over while a settlement was still
   * owed. These two pin the split: one still carries a primary action, the other
   * deliberately carries none.
   */
  it('settlement pending → day summary with a settlement action, not "completed"', () => {
    setup({ trips: [trip({ status: 'settlement_pending' })], manifest: manifest([item()], true), stops: [] });
    render(<DriverHomePage />);
    expect(screen.getByText('home.currentWork.settlementPending')).toBeInTheDocument();
    expect(screen.getByText('home.nextAction.startSettlement')).toBeInTheDocument();
    expect(screen.getByText('home.daySummary.title')).toBeInTheDocument();
    expect(screen.queryByText('home.currentWork.completed')).not.toBeInTheDocument();
  });

  it('closed → day summary and NO action button at all', () => {
    setup({ trips: [trip({ status: 'closed' })], manifest: manifest([item()], true), stops: [] });
    render(<DriverHomePage />);
    expect(screen.getByText('home.currentWork.completed')).toBeInTheDocument();
    expect(screen.getByText('home.daySummary.title')).toBeInTheDocument();
    // A finished day must look finished — not a disabled action.
    expect(screen.queryByText('home.nextAction.startSettlement')).not.toBeInTheDocument();
    expect(screen.queryByText('home.nextAction.nextStop')).not.toBeInTheDocument();
  });

  it('mid-day does NOT show the day summary', () => {
    setup({ trips: [trip({ status: 'out_for_delivery' })], manifest: manifest([item()], true), stops: [] });
    render(<DriverHomePage />);
    expect(screen.queryByText('home.daySummary.title')).not.toBeInTheDocument();
  });

  it('no trip → operational empty state, no action', () => {
    setup({ trips: [] });
    render(<DriverHomePage />);
    expect(screen.getByText('home.currentWork.none')).toBeInTheDocument();
    expect(screen.queryByText('home.nextAction.startLoading')).not.toBeInTheDocument();
  });

  it('a failed fetch shows the error state, not an empty state', () => {
    mTrips.mockReturnValue({ data: undefined, isLoading: false, isError: true, isFetching: false, refetch: vi.fn() });
    mLoading.mockReturnValue({ data: null });
    mStops.mockReturnValue({ data: [] });
    mInv.mockReturnValue({ data: null });
    mSettle.mockReturnValue({ data: undefined });
    render(<DriverHomePage />);
    expect(screen.getByText('loadingScreen.error')).toBeInTheDocument();
    expect(screen.queryByText('home.currentWork.none')).not.toBeInTheDocument();
  });
});

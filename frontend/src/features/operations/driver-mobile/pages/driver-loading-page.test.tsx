import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Selector-mode i18n → dotted path, so assertions are stable without booting i18next.
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
    i18n: { language: 'ar', exists: () => true },
  }),
}));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));

import {
  useDriverLoading,
  useDriverTrips,
  useConfirmReceivedProduct,
  useRequestQuantityAdjustment,
  useCompleteShipmentLoading,
  useStartTrip,
} from '../hooks/use-driver-mobile';

vi.mock('../hooks/use-driver-mobile', () => ({
  useDriverLoading: vi.fn(),
  useDriverTrips: vi.fn(),
  useConfirmReceivedProduct: vi.fn(),
  useRequestQuantityAdjustment: vi.fn(),
  useCompleteShipmentLoading: vi.fn(),
  useStartTrip: vi.fn(),
}));

import { DriverLoadingPage } from './driver-loading-page';
import type { DriverLoadingItem, DriverLoadingManifest, DriverTrip } from '../types/driver-mobile';

const mockLoading = useDriverLoading as unknown as ReturnType<typeof vi.fn>;
const mockTrips = useDriverTrips as unknown as ReturnType<typeof vi.fn>;
const mockConfirmReceived = useConfirmReceivedProduct as unknown as ReturnType<typeof vi.fn>;
const mockRequestAdjustment = useRequestQuantityAdjustment as unknown as ReturnType<typeof vi.fn>;
const mockCompleteMutation = useCompleteShipmentLoading as unknown as ReturnType<typeof vi.fn>;
const mockStartTrip = useStartTrip as unknown as ReturnType<typeof vi.fn>;

const loadMutate = vi.fn();
const adjustMutate = vi.fn();
const completeMutate = vi.fn();
// "Ready to Start Delivery" chains the canonical complete + start via mutateAsync.
const completeAsync = vi.fn().mockResolvedValue({});
const startAsync = vi.fn().mockResolvedValue({});

function item(over: Partial<DriverLoadingItem> = {}): DriverLoadingItem {
  return {
    product_id: 'p-1',
    product_name: 'Honey',
    quantity_required: 20,
    quantity_prepared: 20,
    quantity_loaded: 0,
    quantity_remaining: 20,
    status: 'pending',
    // Custody fields (TASK-...-CUSTODY-IMPLEMENTATION-001). The defaults describe a
    // product the warehouse has already confirmed and the driver has not yet counted —
    // `quantity_driver_received: null` is "not counted", never a counted zero.
    loading_task_id: 'lt-1',
    warehouse_confirmed_at: '2026-08-26T10:00:00+00:00',
    quantity_driver_received: null,
    driver_confirmed_at: null,
    difference: null,
    workflow_state: 'awaiting_driver_confirmation',
    open_adjustment: null,
    ...over,
  };
}

function trip(over: Partial<DriverTrip> = {}): DriverTrip {
  return {
    id: 't-1', trip_number: 'TRIP-1', status: 'loading', company_id: 1, driver_id: 7,
    vehicle_id: 3, vehicle_plate: '1336', vehicle_name: 'Van',
    stops_count: 12, exceptions_count: 0, trip_started_at: null, trip_finished_at: null,
    ...over,
  };
}

function setup(opts: {
  manifest?: DriverLoadingManifest | undefined;
  isLoading?: boolean;
  isError?: boolean;
  trips?: DriverTrip[];
  loadError?: unknown;
} = {}) {
  mockLoading.mockReturnValue({
    data: opts.manifest,
    isLoading: opts.isLoading ?? false,
    isError: opts.isError ?? false,
    refetch: vi.fn(),
  });
  mockTrips.mockReturnValue({ data: opts.trips ?? [trip()], isLoading: false, isFetching: false, refetch: vi.fn() });
  mockConfirmReceived.mockReturnValue({
    mutate: loadMutate,
    isPending: false,
    error: opts.loadError ?? null,
  });
  mockRequestAdjustment.mockReturnValue({
    mutate: adjustMutate,
    isPending: false,
    error: null,
  });
  mockCompleteMutation.mockReturnValue({ mutate: completeMutate, mutateAsync: completeAsync, isPending: false, error: null });
  mockStartTrip.mockReturnValue({ mutate: vi.fn(), mutateAsync: startAsync, isPending: false, isError: false, error: null });
}

const SHIPMENT = { driver_name: 'Ahmed', orders_count: 12, loading_complete: false };

beforeEach(() => {
  loadMutate.mockClear();
  adjustMutate.mockClear();
  completeMutate.mockClear();
  completeAsync.mockClear();
  startAsync.mockClear();
  navigate.mockClear();
});

describe('DriverLoadingPage', () => {
  it('renders the operational header and the handover summary', () => {
    setup({ manifest: { shipment: SHIPMENT, items: [item({ quantity_loaded: 5 })] } });
    render(<DriverLoadingPage />);
    expect(screen.getByText('loadingScreen.headerTitle')).toBeInTheDocument();
    expect(screen.getByText('loadingScreen.handover.products')).toBeInTheDocument();
    expect(screen.getByText('loadingScreen.handover.awaiting')).toBeInTheDocument();
  });

  it('shows Required, Loaded by warehouse, and an uncounted Received', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        // Distinct values so each assertion targets exactly one cell.
        items: [item({ quantity_required: 20, quantity_prepared: 19, quantity_loaded: 18, quantity_remaining: 2 })],
      },
    });
    render(<DriverLoadingPage />);

    expect(screen.getByText('Honey')).toBeInTheDocument();
    expect(screen.getByText('20')).toBeInTheDocument(); // Required (planning reference)
    expect(screen.getByText('18')).toBeInTheDocument(); // Loaded BY THE WAREHOUSE

    // The driver has not counted yet, so Received says so rather than showing 0 —
    // "not counted" and "counted zero" are different facts.
    expect(screen.getByText('loadingScreen.notCounted')).toBeInTheDocument();
  });

  /**
   * TASK-...-CUSTODY-IMPLEMENTATION-001 changed what this screen writes.
   *
   * The driver used to set `quantity_loaded` — the WAREHOUSE's number. Under the
   * approved custody contract the driver confirms only what THEY received, and the
   * warehouse quantity is theirs alone. These two tests pin that boundary.
   */
  it('confirms RECEIPT of the warehouse quantity in one tap, with no editable field', () => {
    setup({ manifest: { shipment: SHIPMENT, items: [item({ quantity_loaded: 20 })] } });
    render(<DriverLoadingPage />);

    // The normal full-receipt path exposes NO editable quantity input.
    expect(screen.queryByTestId('received-input-p-1')).toBeNull();

    fireEvent.click(screen.getByTestId('confirm-received-p-1'));

    // Confirms the WAREHOUSE quantity as received — never re-typed, never a loaded write.
    expect(loadMutate).toHaveBeenCalledWith({
      productId: 'p-1',
      receivedQty: 20,
      expectedLoadedQty: 20,
    });
  });

  it('reports a different received quantity through the adjustment mechanism, revealed on demand', () => {
    setup({ manifest: { shipment: SHIPMENT, items: [item({ quantity_loaded: 20 })] } });
    render(<DriverLoadingPage />);

    // The input appears only after choosing to report a different quantity.
    expect(screen.queryByTestId('received-input-p-1')).toBeNull();
    fireEvent.click(screen.getByText('loadingScreen.reportDifferent'));

    const input = screen.getByTestId('received-input-p-1');
    fireEvent.change(input, { target: { value: '12' } });
    fireEvent.click(screen.getByTestId('request-adjustment-p-1'));

    expect(adjustMutate).toHaveBeenCalledWith({
      productId: 'p-1',
      reportedQty: 12,
      expectedLoadedQty: 20,
    });
    // The driver's action never carries a loaded quantity at all.
    expect(loadMutate).not.toHaveBeenCalled();
  });

  it('hides the driver actions while an adjustment request is open', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        items: [
          item({
            quantity_loaded: 20,
            workflow_state: 'adjustment_requested',
            open_adjustment: {
              id: 'a-1',
              driver_reported_qty: 12,
              quantity_before: 20,
              reason: null,
              requested_at: null,
            },
          }),
        ],
      },
    });
    render(<DriverLoadingPage />);

    expect(screen.getByTestId('pending-p-1')).toBeInTheDocument();
    expect(screen.queryByLabelText(/loadingScreen\.confirmReceived/)).toBeNull();
  });

  it('shows a partial row explicitly (Loaded < Required) without labelling it a shortage', () => {
    setup({ manifest: { shipment: SHIPMENT, items: [item({ quantity_required: 20, quantity_loaded: 15, quantity_remaining: 5 })] } });
    render(<DriverLoadingPage />);
    // Remaining is a neutral fact; the status comes from the custody workflow_state.
    expect(screen.getByText('5')).toBeInTheDocument(); // Remaining
    expect(screen.getByText('loadingScreen.driverState.awaiting_driver_confirmation')).toBeInTheDocument();
  });

  it('departs only on the explicit CTA — Ready to Start Delivery chains complete + start', async () => {
    setup({
      manifest: {
        shipment: SHIPMENT, // loading_complete: false → the CTA completes loading first, then departs
        items: [
          item({ quantity_loaded: 20, quantity_remaining: 0, workflow_state: 'driver_confirmed' }),
        ],
      },
    });
    render(<DriverLoadingPage />);
    // A fully-confirmed shipment must NOT depart by itself.
    expect(completeAsync).not.toHaveBeenCalled();
    expect(startAsync).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId('driver-ready-to-start-delivery'));

    // Reuses the canonical authorities: complete loading, then the canonical trip departure.
    await waitFor(() => expect(startAsync).toHaveBeenCalled());
    expect(completeAsync).toHaveBeenCalled();
  });

  it('State C: blocked trip shows the blocked message and no loading UI', () => {
    setup({ manifest: { shipment: SHIPMENT, items: [item()] }, trips: [trip({ status: 'dispatch_blocked' })] });
    render(<DriverLoadingPage />);
    expect(screen.getByText('loadingScreen.blocked.title')).toBeInTheDocument();
    expect(screen.getByText('loadingScreen.blocked.reason')).toBeInTheDocument();
    expect(screen.queryByText('loadingScreen.readyToStartDelivery')).not.toBeInTheDocument();
  });

  it('State D: error shows the error state with retry', () => {
    setup({ manifest: undefined, isError: true });
    render(<DriverLoadingPage />);
    expect(screen.getByText('loadingScreen.error')).toBeInTheDocument();
    expect(screen.getByText('loadingScreen.retry')).toBeInTheDocument();
  });

  it('surfaces the canonical server refusal verbatim (e.g. over-load 422)', () => {
    const refusal = new Error('Loaded quantity (25) exceeds the planned/allocated quantity (20).');
    setup({ manifest: { shipment: SHIPMENT, items: [item()] }, loadError: refusal });
    render(<DriverLoadingPage />);
    expect(screen.getByRole('alert')).toHaveTextContent('exceeds the planned/allocated quantity');
  });

  // PHASE-2 DEFECT (§3/§7/§22): a shipment flagged loading_complete while an item still
  // awaits this driver must STILL expose that item's Confirm action. Before the fix the
  // whole card was disabled by the shipment `loading_complete` flag, leaving progress at
  // "1/2" with no way to act.
  it('exposes the Confirm action for an awaiting item even when loading_complete is set', () => {
    setup({
      manifest: { shipment: { ...SHIPMENT, loading_complete: true }, items: [item({ quantity_loaded: 1 })] },
      trips: [trip({ status: 'loading_completed' })], // complete flag set, trip NOT departed
    });
    render(<DriverLoadingPage />);
    expect(screen.getByTestId('confirm-received-p-1')).toBeInTheDocument();
  });

  // Once the trip has actually DEPARTED, custody is locked and the item action is frozen —
  // the fix gates on real departure (hasTripDeparted), not the shipment flag.
  it('freezes the item action once the trip has departed (in_progress)', () => {
    setup({
      manifest: { shipment: { ...SHIPMENT, loading_complete: true }, items: [item({ quantity_loaded: 1 })] },
      trips: [trip({ status: 'in_progress' })],
    });
    render(<DriverLoadingPage />);
    expect(screen.queryByTestId('confirm-received-p-1')).not.toBeInTheDocument();
  });

  it('State E: once DEPARTED (on the road) shows the trip-started recap and routes to orders — no financials', () => {
    setup({
      manifest: {
        shipment: { ...SHIPMENT, loading_complete: true },
        items: [
          item({ quantity_loaded: 18, workflow_state: 'driver_confirmed' }),
          item({ product_id: 'p-2', product_name: 'Coffee', quantity_loaded: 15, workflow_state: 'driver_confirmed' }),
        ],
      },
      trips: [trip({ status: 'in_progress' })], // VNEXT: the recap shows once the trip has departed
    });
    const { container } = render(<DriverLoadingPage />);
    expect(screen.getByText('loadingScreen.departedTitle')).toBeInTheDocument();
    // Two products, both confirmed → both tiles read "2".
    expect(screen.getAllByText('2').length).toBeGreaterThan(0);
    fireEvent.click(screen.getByText('loadingScreen.next'));
    expect(navigate).toHaveBeenCalled();
    const text = (container.textContent ?? '').toLowerCase();
    for (const forbidden of ['wallet', 'expense', 'settlement', 'egp']) {
      expect(text).not.toContain(forbidden);
    }
  });

  it('an unresolved fetch is NOT rendered as "no shipment" (401/403 masking guard)', () => {
    // React Query leaves isLoading false during retry backoff; data is still undefined.
    setup({ manifest: undefined, isLoading: false, isError: false });
    render(<DriverLoadingPage />);
    expect(screen.queryByText('home.empty.title')).not.toBeInTheDocument();
    expect(screen.queryByText('loadingScreen.readyToStartDelivery')).not.toBeInTheDocument();
  });

  it('State B: no shipment shows the safe empty state', () => {
    setup({ manifest: { shipment: null, items: [] }, trips: [] });
    render(<DriverLoadingPage />);
    expect(screen.getByText('home.empty.title')).toBeInTheDocument();
  });

  it('never renders NaN', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        items: [item({ quantity_loaded: undefined as unknown as number, quantity_remaining: undefined as unknown as number })],
      },
    });
    const { container } = render(<DriverLoadingPage />);
    expect(container.textContent).not.toContain('NaN');
  });

  // ── Loading Complete gate (TASK-LOADING-DRIVER-COMPLETE-GATE-001) ──────────
  //
  // A shipment must not close while the warehouse has loaded something this driver
  // has not acknowledged. The button mirrors the server's rule; the server refuses
  // regardless, so these tests pin the UX, not the protection.

  it('CASE A: disables Loading Complete while an item awaits driver confirmation', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        items: [
          item({ product_id: 'p-1', quantity_loaded: 1, workflow_state: 'driver_confirmed' }),
          item({ product_id: 'p-2', quantity_loaded: 1, workflow_state: 'awaiting_driver_confirmation' }),
        ],
      },
    });
    render(<DriverLoadingPage />);

    expect(screen.getByTestId('driver-ready-to-start-delivery')).toBeDisabled();
    expect(screen.getByTestId('driver-loading-pending-reason')).toBeInTheDocument();
    expect(completeMutate).not.toHaveBeenCalled();
  });

  it('CASE B: enables Loading Complete once every loaded item is confirmed', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        items: [
          item({ product_id: 'p-1', quantity_loaded: 1, workflow_state: 'driver_confirmed' }),
          item({ product_id: 'p-2', quantity_loaded: 1, workflow_state: 'driver_confirmed' }),
        ],
      },
    });
    render(<DriverLoadingPage />);

    const button = screen.getByTestId('driver-ready-to-start-delivery');
    expect(button).toBeEnabled();
    expect(screen.queryByTestId('driver-loading-pending-reason')).toBeNull();

    fireEvent.click(button);
    expect(completeMutate).toHaveBeenCalled();
  });

  it('CASE C: an item with nothing loaded does not block completion', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        // Nothing was loaded, so there is no custody to acknowledge.
        items: [item({ quantity_loaded: 0, workflow_state: 'awaiting_driver_confirmation' })],
      },
    });
    render(<DriverLoadingPage />);

    expect(screen.getByTestId('driver-ready-to-start-delivery')).toBeEnabled();
  });

  it('CASE D: a raised discrepancy is the driver acting, so it does not block', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        items: [
          item({
            quantity_loaded: 3,
            workflow_state: 'adjustment_requested',
            open_adjustment: {
              id: 'a-1',
              driver_reported_qty: 2,
              quantity_before: 3,
              reason: null,
              requested_at: null,
            },
          }),
        ],
      },
    });
    render(<DriverLoadingPage />);

    expect(screen.getByTestId('driver-ready-to-start-delivery')).toBeEnabled();
  });

  it('CASE E: a stale confirmation blocks completion again', () => {
    setup({
      manifest: {
        shipment: SHIPMENT,
        // The warehouse revised after the driver agreed — they never accepted this number.
        items: [item({ quantity_loaded: 4, workflow_state: 'awaiting_driver_reconfirmation' })],
      },
    });
    render(<DriverLoadingPage />);

    expect(screen.getByTestId('driver-ready-to-start-delivery')).toBeDisabled();
    expect(screen.getByTestId('driver-loading-pending-reason')).toBeInTheDocument();
  });
});

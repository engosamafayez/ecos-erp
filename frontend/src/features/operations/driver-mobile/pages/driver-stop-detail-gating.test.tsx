import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Render the typed `t(($) => $.a.b.c)` selector as its dotted key string, so assertions can
// match on the key. Same idiom as driver-reports.test.tsx.
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
vi.mock('react-router-dom', () => ({ useParams: () => ({ tripId: 'T1', stopId: 'S1' }), useNavigate: () => vi.fn() }));
vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => String(n) }) }));
vi.mock('@/components/ds/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));
// Trivial stubs — none are relevant to the gating logic, and the sheet forms never mount here.
vi.mock('../components/stop-status-badge', () => ({ StopStatusBadge: () => null }));
vi.mock('../components/delivery-action-form', () => ({ DeliveryActionForm: () => null }));
vi.mock('../components/delivery-proof-upload-form', () => ({ DeliveryProofUploadForm: () => null }));
vi.mock('../components/payment-proof-upload-form', () => ({ PaymentProofUploadForm: () => null }));

const stopHolder: { data: unknown; isLoading: boolean } = { data: undefined, isLoading: false };
const tripHolder: { data: unknown } = { data: undefined };
const idleMutation = { isPending: false, isError: false, error: null, mutate: vi.fn() };

vi.mock('../hooks/use-driver-mobile', () => ({
  useDriverStopDetail: () => stopHolder,
  useDriverTrip: () => tripHolder,
  useSubmitDeliveryAction: () => idleMutation,
  useSubmitStopDelivery: () => idleMutation,
  useStartDelivery: () => idleMutation,
  useUploadDeliveryProof: () => idleMutation,
  useUploadPaymentProof: () => idleMutation,
  useChangePaymentMethod: () => idleMutation,
}));

// IMPORTANT: `../lib/trip-lifecycle` is intentionally NOT mocked — the test exercises the real
// canonical `acceptsDeliveryExecution` predicate, which mirrors the backend on-the-road guard.
import { DriverStopDetailPage } from './driver-stop-detail-page';

const LINE = {
  order_line_id: 'L1', product_id: 1, product_name: 'P', ordered_qty: 5, unit_price: 10,
  line_total: 50, loaded_qty: 5, delivered_qty: 0, returned_qty: 0, remaining_qty: 5,
};

function makeStop(status: string, lines: unknown[] = []) {
  return {
    id: 'S1', sequence: 1, status, delivery_type: null, notes: null,
    collected_amount: 0, payment_method: 'cod', attempted_at: null, completed_at: null,
    order: {
      id: 1, order_number: 'ORD-1', customer_name: 'Cust', phone: null,
      address: 'A', area: 'Ar', city: 'C', governorate: 'G', gps: null,
      payment_method: 'cod', grand_total: 100, deposit_paid: 0, remaining_balance: 100,
      items_count: lines.length, delivery_notes: null, lines,
    },
    collections: [], proof: null,
  };
}

const START = 'stop.startDelivery';
const BLOCK_HINT = 'stop.startBlockedTripNotOnRoad';
const OUTCOMES = 'stop.reportOutcome';
const CHANGE_METHOD = 'stop.changeMethod.button';

describe('Driver stop detail — Start Delivery trip-lifecycle gating', () => {
  it('trip Loading + stop pending → Start Delivery hidden, explanatory hint shown', () => {
    stopHolder.data = makeStop('pending');
    tripHolder.data = { id: 'T1', status: 'loading' };
    render(<DriverStopDetailPage />);

    expect(screen.queryByText(START)).not.toBeInTheDocument();
    expect(screen.getByText(BLOCK_HINT)).toBeInTheDocument();
  });

  it('trip Ready-for-dispatch (not departed) + stop pending → Start Delivery still hidden', () => {
    stopHolder.data = makeStop('pending');
    tripHolder.data = { id: 'T1', status: 'ready_for_dispatch' };
    render(<DriverStopDetailPage />);

    expect(screen.queryByText(START)).not.toBeInTheDocument();
    expect(screen.getByText(BLOCK_HINT)).toBeInTheDocument();
  });

  it('trip Dispatched (on the road) + stop pending → Start Delivery exposed, no hint', () => {
    stopHolder.data = makeStop('pending');
    tripHolder.data = { id: 'T1', status: 'dispatched' };
    render(<DriverStopDetailPage />);

    expect(screen.getByText(START)).toBeInTheDocument();
    expect(screen.queryByText(BLOCK_HINT)).not.toBeInTheDocument();
  });

  it('trip In-progress + stop in_progress → execution controls exposed (outcomes + change method)', () => {
    stopHolder.data = makeStop('in_progress', [LINE]);
    tripHolder.data = { id: 'T1', status: 'in_progress' };
    render(<DriverStopDetailPage />);

    expect(screen.getByText(OUTCOMES)).toBeInTheDocument();
    expect(screen.getByText(CHANGE_METHOD)).toBeInTheDocument();
    // Not pending → no Start Delivery button.
    expect(screen.queryByText(START)).not.toBeInTheDocument();
  });

  it('§2 consistency: trip Loading + stop in_progress (regressed) → NO execution controls exposed', () => {
    // A stop-level state must never expose an action the parent trip cannot legally perform.
    stopHolder.data = makeStop('in_progress', [LINE]);
    tripHolder.data = { id: 'T1', status: 'loading' };
    render(<DriverStopDetailPage />);

    expect(screen.queryByText(OUTCOMES)).not.toBeInTheDocument();
    expect(screen.queryByText(CHANGE_METHOD)).not.toBeInTheDocument();
    expect(screen.queryByText(START)).not.toBeInTheDocument();
  });
});

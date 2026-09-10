import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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
    i18n: { language: 'en', exists: () => true },
  }),
}));
vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({
    money: (v: number) => `EGP ${v}`,
    currency: 'EGP',
    number: (v: number) => String(v),
    percent: (v: number) => `${v}%`,
    dateTime: (v: string) => `dt:${v}`,
  }),
}));
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  useParams: () => ({ assignmentId: '12' }),
  useSearchParams: () => [new URLSearchParams('date=2026-08-24'), vi.fn()],
}));
vi.mock('@/features/authorization', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/components/ds/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));
// Stub the heavy canonical components so this test stays about the rollup page.
vi.mock('@/features/logistics/trips/components/trip-settlement-tab', () => ({
  TripSettlementTab: ({ tripId }: { tripId: string }) => <div data-testid="trip-settlement-tab">{tripId}</div>,
}));
vi.mock('@/features/logistics/trips/services/trip-settlement-service', () => ({
  tripSettlementService: { finalize: vi.fn() },
}));
vi.mock('@/features/orders/components/payment-proof-section', () => ({ PaymentProofSection: () => <div /> }));
vi.mock('@/features/orders/components/order-detail-drawer', () => ({ OrderDetailDrawer: () => null }));

import { useDriverSettlementDetail } from '../hooks/use-driver-settlement';
vi.mock('../hooks/use-driver-settlement', () => ({
  useDriverSettlementDetail: vi.fn(),
  // The movement-review component uses this; return idle mutations so the detail renders.
  useReviewDriverMovement: () => ({
    approve: { mutate: vi.fn(), isPending: false },
    reject: { mutate: vi.fn(), isPending: false },
  }),
  // The Returns tab's Confirm Receipt section uses this; an idle mutation is enough for
  // these tests, none of which exercise the receive flow itself.
  useReceiveVehicleReturn: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { DriverSettlementDetailPage } from './driver-settlement-detail-page';
import type { DaySettlementDriverDetail } from '../types/driver-settlement';

const mockDetail = useDriverSettlementDetail as unknown as ReturnType<typeof vi.fn>;

function baseDetail(over: Partial<DaySettlementDriverDetail> = {}): DaySettlementDriverDetail {
  return {
    date: '2026-08-24',
    driver: { id: 7, name: 'Ahmed Samir', vehicle_id: 3, vehicle_plate: 'ABC-123' },
    settlement_status: 'under_review',
    closing_stage: 'ready_for_closing',
    overview: { orders: 18, delivered: 17, partial: 0, failed: 1, failed_breakdown: { no_answer: 1, postponed: 0, other: 0 }, returns: 1, delivery_pct: 94, trips: 1 },
    cash_handover: { confirmed_count: 0, total_confirmed_trips: 1, total_received_cash: 0, trip_ids_confirmed: [], last_confirmed_at: null },
    financial: { cash_expected: 4850, approved_transfers: 1200, actual_cash: 4850, difference: 0, is_balanced: true, cash_collected: 4850, expenses: 0, cash_in: 0, net_cash: 4850 },
    movements: { available: true, items: [], pending_count: 0, approved_expenses: 0, approved_cash_in: 0, expenses_by_category: {} },
    collections: {
      cash: 4850,
      bank_transfer: 1150,
      card: 0,
      already_paid: 0,
      total_collected: 6000,
      delivered_sales: 6200,
      actual_collected: 6000,
      cash_expected: 4850,
      actual_cash: 4850,
      expected_collection: null,
      expected_collection_available: false,
      collection_difference: null,
      collection_difference_pending: false,
    },
    custody_summary: {
      reconciliation_available: false,
      reconciliation_status: null,
      total_loaded: 0,
      total_delivered: 0,
      expected_return: 0,
      actual_return: 0,
      accepted: 0,
      damaged: 0,
      shortage: 0,
      remaining_on_hand: 0,
      lines_total: 0,
      lines_received: 0,
    },
    product_reconciliation: [],
    damage: { available: false, gap: 'waste_investigation_deferred', items: [] },
    shortage_review: { available: false, gap: 'liability_attribution_deferred', liability_confirmed: false, items: [] },
    closing_readiness: { ready: true, blockers: [] },
    timeline: [],
    trips: [{ id: 't-1', trip_number: 'TRIP-1', settlement_status: 'reconciled', cash_expected: 4850, difference: 0, stops_total: 18, stops_outstanding: 1 }],
    orders: [],
    transfers: [],
    returns: [],
    goods_remaining: [],
    ...over,
  };
}

function withDetail(detail: DaySettlementDriverDetail) {
  mockDetail.mockReturnValue({ data: detail, isLoading: false, isError: false, refetch: vi.fn() });
}

describe('DriverSettlementDetailPage', () => {
  it('renders the driver header and overview figures', () => {
    withDetail(baseDetail());
    render(<DriverSettlementDetailPage />);
    expect(screen.getByText('Ahmed Samir')).toBeInTheDocument();
    // approved_transfers is a unique figure in the financial strip.
    expect(screen.getByText('EGP 1200')).toBeInTheDocument();
    expect(screen.getByText('driverSettlement.tabs.settlement')).toBeInTheDocument();
  });

  it('shows the difference banner when the settlement is not balanced', () => {
    withDetail(baseDetail({ financial: { cash_expected: 4850, approved_transfers: 1200, actual_cash: 4800, difference: -50, is_balanced: false, cash_collected: 4800, expenses: 0, cash_in: 0, net_cash: 4800 } }));
    render(<DriverSettlementDetailPage />);
    expect(screen.getByText('driverSettlement.differenceBanner')).toBeInTheDocument();
  });

  it('shows the completed banner when the day is settled', () => {
    withDetail(baseDetail({ settlement_status: 'settled' }));
    render(<DriverSettlementDetailPage />);
    expect(screen.getByText('driverSettlement.completedBanner')).toBeInTheDocument();
  });

  it('rolls up the driver day per trip (Overview lists each trip)', () => {
    withDetail(baseDetail());
    render(<DriverSettlementDetailPage />);
    // The default Overview tab lists each of the driver's trips — the day view is a
    // rollup over trips, and the Settlement tab reuses TripSettlementTab per trip.
    expect(screen.getByText('TRIP-1')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'driverSettlement.tabs.settlement' })).toBeInTheDocument();
  });

  it('shows an error state when the detail fails to load', () => {
    mockDetail.mockReturnValue({ data: undefined, isLoading: false, isError: true, refetch: vi.fn() });
    render(<DriverSettlementDetailPage />);
    expect(screen.getByText('driverSettlement.loadError')).toBeInTheDocument();
  });

  // Change 2 (TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023) — cash_handover is a new
  // read-only rollup of Treasury's Trip cash-handover confirmations. A missing confirmation
  // must render as an explicit "not yet confirmed" state, never a blank or a confirmed-looking
  // zero — this is the behaviour under test here, not just that the numbers appear.
  it('shows cash handover as not yet confirmed when Treasury has not confirmed any trip', () => {
    withDetail(baseDetail());
    render(<DriverSettlementDetailPage />);
    expect(screen.getByText('driverSettlement.detail.cashHandover.notYetConfirmed')).toBeInTheDocument();
    expect(screen.queryByText('driverSettlement.detail.cashHandover.confirmed')).not.toBeInTheDocument();
  });

  it('shows the confirmed cash handover total and last-confirmed time once Treasury confirms', () => {
    withDetail(baseDetail({
      cash_handover: {
        confirmed_count: 1,
        total_confirmed_trips: 1,
        total_received_cash: 3333,
        trip_ids_confirmed: [501],
        last_confirmed_at: '2026-08-24T15:30:00Z',
      },
    }));
    render(<DriverSettlementDetailPage />);
    expect(screen.getByText('driverSettlement.detail.cashHandover.confirmed')).toBeInTheDocument();
    expect(screen.getByText('EGP 3333')).toBeInTheDocument();
    expect(screen.getByText('dt:2026-08-24T15:30:00Z')).toBeInTheDocument();
  });
});

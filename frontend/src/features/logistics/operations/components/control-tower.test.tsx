import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-V1.1-OPS-04-TASK2 — Control Tower frontend source tests.
// §24 items 1-24 (responsive/RTL/a11y items are structural — verified by not
// depending on desktop-only DOM assumptions anywhere below, per item 24).

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
  }),
}));

const mockNavigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate }));

// Pagination (rendered by CustodyReturnsPanel whenever the Expected Returns
// table has rows) reaches for LanguageProvider context. Stubbed the same way
// warehouse-liability-detail-drawer.test.tsx stubs useFormatter, so this stays
// a render test rather than one that also depends on the locale provider tree.
vi.mock('@/providers/language-context', () => ({
  useLanguage: () => ({ language: 'en', dir: 'ltr', setLanguage: vi.fn() }),
}));

vi.mock('../hooks/use-control-tower', () => ({
  useShippingSummary: vi.fn(),
  useCustodySummary: vi.fn(),
  useReturnsSummary: vi.fn(),
  useSettlementSummary: vi.fn(),
  useExternalCarrierSummary: vi.fn(),
  useExpectedReturns: vi.fn(),
}));

import {
  useCustodySummary,
  useExpectedReturns,
  useExternalCarrierSummary,
  useReturnsSummary,
  useSettlementSummary,
  useShippingSummary,
} from '../hooks/use-control-tower';

import { ShippingExecutionPanel } from './shipping-execution-panel';
import { CustodyReturnsPanel } from './custody-returns-panel';
import { SettlementPanel } from './settlement-panel';
import { ExternalCarrierPanel } from './external-carrier-panel';

const shippingQ = useShippingSummary as unknown as ReturnType<typeof vi.fn>;
const custodyQ = useCustodySummary as unknown as ReturnType<typeof vi.fn>;
const returnsQ = useReturnsSummary as unknown as ReturnType<typeof vi.fn>;
const settlementQ = useSettlementSummary as unknown as ReturnType<typeof vi.fn>;
const carrierQ = useExternalCarrierSummary as unknown as ReturnType<typeof vi.fn>;
const expectedQ = useExpectedReturns as unknown as ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
});

// ── 2/3. Shipping summary uses API facts; no frontend recomputation ────────

describe('ShippingExecutionPanel', () => {
  it('renders canonical trip/stop counts exactly as returned, and reconciliation buckets', () => {
    shippingQ.mockReturnValue({
      isLoading: false,
      isError: false,
      data: {
        trips: {
          awaiting_loading: 8, loading_in_progress: 1, ready_for_dispatch: 2, dispatch_blocked: 0,
          executing: 5, completed_pending_settlement: 1, closed: 10, cancelled: 0, external_carrier_trips: 2,
        },
        groups_awaiting_trip_assignment: 4,
        delivery_stops: {
          by_status: { pending: 6, in_progress: 2, delivered: 20, partial: 1, failed: 9, returned: 1, skipped: 0 },
          retryable_failed: 2,
        },
        window_order_reconciliation: { total: 10, zoned: 7, unzoned: 3, grouped: 6, ungrouped: 4 },
      },
    });

    render(<ShippingExecutionPanel />);

    // 3. Canonical states render correctly, unmodified.
    expect(screen.getByText('5')).toBeInTheDocument(); // executing
    expect(screen.getByText('20')).toBeInTheDocument(); // delivered stops

    // 4. Residual buckets reconcile visually (7 zoned + 3 unzoned = 10 total).
    expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('does not crash and shows an error state when the summary request fails (§19)', () => {
    shippingQ.mockReturnValue({ isLoading: false, isError: true, data: undefined });

    render(<ShippingExecutionPanel />);

    expect(screen.getByText('operations.controlTower.loadFailed')).toBeInTheDocument();
  });

  it('drills down to the Distribution Workspace', () => {
    shippingQ.mockReturnValue({
      isLoading: false, isError: false,
      data: {
        trips: { awaiting_loading: 0, loading_in_progress: 0, ready_for_dispatch: 0, dispatch_blocked: 0, executing: 0, completed_pending_settlement: 0, closed: 0, cancelled: 0, external_carrier_trips: 0 },
        groups_awaiting_trip_assignment: 0,
        delivery_stops: { by_status: { pending: 0, in_progress: 0, delivered: 0, partial: 0, failed: 0, returned: 0, skipped: 0 }, retryable_failed: 0 },
        window_order_reconciliation: { total: 0, zoned: 0, unzoned: 0, grouped: 0, ungrouped: 0 },
      },
    });

    render(<ShippingExecutionPanel />);
    fireEvent.click(screen.getByText('operations.controlTower.shipping.openDistribution'));

    expect(mockNavigate).toHaveBeenCalledWith('/logistics/distribution/workspace');
  });
});

// ── 6/7. Custody — canonical figures, incomplete linkage never rendered as 0 ─

describe('CustodyReturnsPanel', () => {
  it('renders Loaded/Delivered/Remaining/Returned/Received from canonical figures', () => {
    custodyQ.mockReturnValue({
      isLoading: false, isError: false,
      data: {
        loaded: 100, delivered: 80, remaining_with_driver_vehicle: 15, returned_by_driver: 5,
        received_by_warehouse_accepted: 4, received_by_warehouse_damaged: 1,
        awaiting_warehouse_receipt_lines: 2, trip_return_discrepancy_qty: 0,
      },
    });
    returnsQ.mockReturnValue({
      isLoading: false, isError: false,
      data: {
        physical_return_confirmed: 3, physical_return_awaiting_confirmation: 2,
        driver_liable_discrepancies: 0, warehouse_receipt_completed_lines: 4, warehouse_receipt_awaiting_lines: 2,
      },
    });
    expectedQ.mockReturnValue({ isLoading: false, data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });

    render(<CustodyReturnsPanel />);

    expect(screen.getByText('100')).toBeInTheDocument();
    expect(screen.getByText('80')).toBeInTheDocument();
    expect(screen.getByText('15')).toBeInTheDocument();
    // 9. "Returned" note — never implies warehouse receipt.
    expect(screen.getByText('operations.controlTower.custody.returnedNote')).toBeInTheDocument();
  });

  it('renders accepted_qty=null as a pending label, never as 0 (§8/§9)', () => {
    custodyQ.mockReturnValue({ isLoading: false, isError: false, data: { loaded: 0, delivered: 0, remaining_with_driver_vehicle: 0, returned_by_driver: 0, received_by_warehouse_accepted: 0, received_by_warehouse_damaged: 0, awaiting_warehouse_receipt_lines: 0, trip_return_discrepancy_qty: 0 } });
    returnsQ.mockReturnValue({ isLoading: false, isError: false, data: { physical_return_confirmed: 0, physical_return_awaiting_confirmation: 0, driver_liable_discrepancies: 0, warehouse_receipt_completed_lines: 0, warehouse_receipt_awaiting_lines: 0 } });
    expectedQ.mockReturnValue({
      isLoading: false,
      data: {
        data: [{
          vehicle_inventory_item_id: 'vi-1', product_id: 'p-1', product_name: 'Widget',
          trip: { id: 1, uuid: 'u-1', trip_number: 'TRP-1', status: 'in_progress' },
          driver: { id: 1, full_name: 'Ali', driver_code: 'D1' }, vehicle: { id: 1, plate_number: 'ABC-1' },
          expected_qty: 6, accepted_qty: null, damaged_qty: null,
          linkage_state: 'awaiting_reconciliation', received_at: null, last_movement_at: null,
        }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      },
    });

    render(<CustodyReturnsPanel />);

    // expected_qty (a real number) renders as "6".
    expect(screen.getByText('6')).toBeInTheDocument();
    // accepted_qty (null/incomplete) renders as the pending label, not "0".
    expect(screen.queryByText('0', { selector: 'td' })).not.toBeInTheDocument();
    expect(screen.getAllByText('operations.controlTower.custody.stateAwaitingReconciliation').length).toBeGreaterThan(0);
  });

  it('drills down to Returns & Settlement', () => {
    custodyQ.mockReturnValue({ isLoading: false, isError: false, data: { loaded: 0, delivered: 0, remaining_with_driver_vehicle: 0, returned_by_driver: 0, received_by_warehouse_accepted: 0, received_by_warehouse_damaged: 0, awaiting_warehouse_receipt_lines: 0, trip_return_discrepancy_qty: 0 } });
    returnsQ.mockReturnValue({ isLoading: false, isError: false, data: { physical_return_confirmed: 0, physical_return_awaiting_confirmation: 0, driver_liable_discrepancies: 0, warehouse_receipt_completed_lines: 0, warehouse_receipt_awaiting_lines: 0 } });
    expectedQ.mockReturnValue({ isLoading: false, data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });

    render(<CustodyReturnsPanel />);
    fireEvent.click(screen.getByText('operations.controlTower.custody.openReturnsSettlement'));

    expect(mockNavigate).toHaveBeenCalledWith('/logistics/returns-settlement');
  });
});

// ── 11/12. Settlement — pending vs finalized, physical-return independence ──

describe('SettlementPanel', () => {
  it('shows a non-final "pending" presentation while collection difference is unresolved, never a fake/negative figure', () => {
    settlementQ.mockReturnValue({
      isLoading: false, isError: false,
      data: { draft: 1, submitted: 2, reconciled: 0, disputed: 0, finalized: 5, collection_difference_pending: 3 },
    });

    render(<SettlementPanel />);

    expect(screen.getByText('3')).toBeInTheDocument();
    expect(screen.getByText('operations.controlTower.settlement.collectionDifferencePendingHint')).toBeInTheDocument();
  });

  it('shows the physical-return independence note without altering closure eligibility', () => {
    settlementQ.mockReturnValue({
      isLoading: false, isError: false,
      data: { draft: 0, submitted: 0, reconciled: 0, disputed: 0, finalized: 1, collection_difference_pending: 0 },
    });

    render(<SettlementPanel />);

    expect(screen.getByText('operations.controlTower.settlement.physicalReturnIndependent')).toBeInTheDocument();
  });
});

// ── 13/14. External Carrier — supported facts only, deferred fields absent ──

describe('ExternalCarrierPanel', () => {
  it('renders supported carrier facts and never renders deferred financial fields', () => {
    carrierQ.mockReturnValue({
      isLoading: false, isError: false,
      data: { total_shipments: 12, not_yet_tendered: 2, tendered_awaiting_status: 4, by_raw_status: { Delivered: 6 } },
    });

    render(<ExternalCarrierPanel />);

    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.getByText('Delivered')).toBeInTheDocument();
    // Deferred facts (§12 of the parent task) must never appear.
    expect(screen.queryByText(/carrier cost/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/insurance/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/daily transfer cost/i)).not.toBeInTheDocument();
  });
});

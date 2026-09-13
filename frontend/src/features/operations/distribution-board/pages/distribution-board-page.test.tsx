import '@testing-library/jest-dom';

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

import { LanguageContext } from '@/providers/language-context';
import type {
  ActiveWave,
  BoardZone,
  DistributionBoardData,
  DistributionTrip,
  PoolOrder,
} from '../types/distribution-board';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-03-LIST-TABLE-FILTER-WORK-QUEUE-047 — page-level
 * proof that the canonical QueueSection primitive, now driving both the
 * Orders Pool panel and the Today's Trips panel, still wires real queue
 * items, real zone-tab filtering, and real action callbacks correctly.
 * `TripCard` itself (unmodified by this ticket) is the real component under
 * every trip queue item — only its own unrelated sub-panels
 * (ResourceAssignmentPanel/CustodyPanel/CoverageMap, each with their own deep
 * fleet/coverage data requirements this ticket never touches) are stubbed,
 * matching this repo's own precedent (customers-page.test.tsx stubs
 * out-of-scope drawers the same way) rather than mocking TripCard itself.
 */

const mockFetchBoard = vi.hoisted(() => vi.fn());
const mockFetchZoneOrders = vi.hoisted(() => vi.fn());
const mockValidateBoard = vi.hoisted(() => vi.fn());
const mockFetchWaveExceptions = vi.hoisted(() => vi.fn());
const mockFetchTripOrders = vi.hoisted(() => vi.fn());
const mockAutoFillTrip = vi.hoisted(() => vi.fn());
const mockDeleteTrip = vi.hoisted(() => vi.fn());
const mockApproveTrip = vi.hoisted(() => vi.fn());
const mockRemoveOrderFromTrip = vi.hoisted(() => vi.fn());
const mockFinalizeBoard = vi.hoisted(() => vi.fn());
const mockCreateTrip = vi.hoisted(() => vi.fn());
const mockUpdateTrip = vi.hoisted(() => vi.fn());
const mockNavigate = vi.hoisted(() => vi.fn());

vi.mock('../services/distribution-board-service', () => ({
  fetchBoard: mockFetchBoard,
  fetchZoneOrders: mockFetchZoneOrders,
  validateBoard: mockValidateBoard,
  fetchWaveExceptions: mockFetchWaveExceptions,
  fetchTripOrders: mockFetchTripOrders,
  autoFillTrip: mockAutoFillTrip,
  deleteTrip: mockDeleteTrip,
  approveTrip: mockApproveTrip,
  removeOrderFromTrip: mockRemoveOrderFromTrip,
  finalizeBoard: mockFinalizeBoard,
  createTrip: mockCreateTrip,
  updateTrip: mockUpdateTrip,
  moveOrderBetweenTrips: vi.fn(),
  returnOrderToWave: vi.fn(),
  addOrderToTrip: vi.fn(),
}));

// Out of this ticket's scope entirely — TripCard's own sub-panels for fleet
// assignment, custody, and coverage mapping, each with their own deep
// service surface unrelated to the queue-primitive integration under test.
vi.mock('../components/resource-assignment-panel', () => ({ ResourceAssignmentPanel: () => null }));
vi.mock('../components/custody-panel', () => ({ CustodyPanel: () => null }));
vi.mock('../components/coverage-map', () => ({ CoverageMap: () => null }));

vi.mock('@/components/ds/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => mockNavigate };
});

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { defaultValue?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      if (opts?.defaultValue) return opts.defaultValue;
      return key;
    },
  }),
}));

import { DistributionBoardPage } from './distribution-board-page';

function wave(overrides: Partial<ActiveWave> = {}): ActiveWave {
  return {
    id: 'wave-1',
    wave_number: 'WAVE-001',
    planning_date: '2026-01-10',
    status: 'planning',
    orders_count: 10,
    warehouse_id: 1,
    created_at: '2026-01-10T00:00:00Z',
    summary: { total_orders: 10, assigned_orders: 5, unassigned_orders: 5, total_value: 5000, trip_count: 1 },
    ...overrides,
  };
}

function zone(overrides: Partial<BoardZone> = {}): BoardZone {
  return {
    zone_id: 1,
    name_en: 'North Zone',
    name_ar: 'North Zone (AR)',
    code: 'NZ',
    color: '#3366ff',
    total_orders: 5,
    assigned_orders: 2,
    unassigned_orders: 3,
    total_value: 2500,
    ...overrides,
  };
}

function trip(overrides: Partial<DistributionTrip> = {}): DistributionTrip {
  return {
    id: 'trip-1',
    preparation_wave_id: 'wave-1',
    distribution_zone_id: 1,
    trip_number: 'TRIP-001',
    name: 'Morning Run',
    type: 'company_vehicle',
    capacity: 20,
    orders_count: 2,
    collection_amount: 800,
    capacity_usage_percent: 10,
    capacity_status: 'ok',
    status: 'planning',
    notes: null,
    finalized_at: null,
    created_at: '2026-01-10T00:00:00Z',
    fleet_vehicle_id: null,
    fleet_driver_id: null,
    external_carrier_id: null,
    driver_name: null,
    driver_phone: null,
    vehicle: null,
    driver: null,
    carrier: null,
    custody_items: [],
    is_ready_for_loading: false,
    ...overrides,
  };
}

function poolOrder(overrides: Partial<PoolOrder> = {}): PoolOrder {
  return {
    order_id: 'order-1',
    order_number: 'ORD-000001',
    grand_total: 250,
    status: 'confirmed',
    city_name: 'Cairo',
    governorate_name: 'Cairo',
    delivery_zone_snapshot: null,
    zone_code_snapshot: null,
    customer_name: 'Nadia Farouk',
    customer_phone: '01000000000',
    ...overrides,
  };
}

function board(overrides: Partial<DistributionBoardData> = {}): DistributionBoardData {
  return {
    wave: wave(),
    zones: [zone()],
    trips: [trip()],
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        {/* WaveHeader's useFormatter() -> useLocale() reads useLanguage() — a
            real dependency, not a test artifact; see the same fix in
            purchase-orders-page.test.tsx for why this is a minimal context
            value rather than the full LanguageProvider. */}
        <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
          <DistributionBoardPage />
        </LanguageContext.Provider>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockFetchBoard.mockResolvedValue(board());
  mockFetchZoneOrders.mockResolvedValue({ orders: [poolOrder()] });
  mockValidateBoard.mockResolvedValue({ ready: true, issues: [] });
  mockFetchWaveExceptions.mockResolvedValue({ exceptions: [], count: 0 });
});

describe('DistributionBoardPage — canonical QueueSection composition', () => {
  it('renders real queue sections with real items: the trips queue and the orders-pool queue', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText('Morning Run')).toBeInTheDocument();
    expect(screen.getByText('TRIP-001')).toBeInTheDocument();

    // The Orders Pool queue is keyed off the explicitly-clicked zone tab (a
    // pre-existing behavior this ticket doesn't touch: the visually-active
    // first zone doesn't auto-fetch its orders until a tab click sets
    // activeZoneId) — click it, matching the actual real page contract.
    await user.click(screen.getByRole('button', { name: /north zone/i }));

    expect(await screen.findByText(/ORD-000001/)).toBeInTheDocument();
    expect(screen.getByText('Nadia Farouk')).toBeInTheDocument();
  });

  it('filter interaction: selecting a different zone tab requests and shows that zone\'s data', async () => {
    const user = userEvent.setup();
    mockFetchBoard.mockResolvedValue(
      board({
        zones: [zone({ zone_id: 1, name_en: 'North Zone' }), zone({ zone_id: 2, name_en: 'South Zone' })],
        trips: [
          trip({ id: 'trip-1', distribution_zone_id: 1, name: 'North Run' }),
          trip({ id: 'trip-2', distribution_zone_id: 2, name: 'South Run' }),
        ],
      }),
    );
    renderPage();

    expect(await screen.findByText('North Run')).toBeInTheDocument();
    expect(screen.queryByText('South Run')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /south zone/i }));

    expect(await screen.findByText('South Run')).toBeInTheDocument();
    expect(screen.queryByText('North Run')).not.toBeInTheDocument();
    await waitFor(() => expect(mockFetchZoneOrders).toHaveBeenLastCalledWith(2));
  });

  it('shows loading skeletons while the board query is in flight', async () => {
    let resolveBoard!: (v: DistributionBoardData) => void;
    mockFetchBoard.mockReturnValue(new Promise((resolve) => { resolveBoard = resolve; }));
    const { container } = renderPage();

    expect(container.querySelectorAll('[data-slot="skeleton"], .animate-pulse').length).toBeGreaterThan(0);
    resolveBoard(board());
    expect(await screen.findByText('Morning Run')).toBeInTheDocument();
  });

  it('shows the trips-queue empty state when the active zone has no trips', async () => {
    mockFetchBoard.mockResolvedValue(board({ trips: [] }));
    renderPage();
    expect(await screen.findByText('No trips yet')).toBeInTheDocument();
  });

  it('shows the orders-pool empty state when the zone has no unassigned orders', async () => {
    const user = userEvent.setup();
    mockFetchZoneOrders.mockResolvedValue({ orders: [] });
    renderPage();
    await screen.findByText('Morning Run');
    await user.click(screen.getByRole('button', { name: /north zone/i }));
    expect(await screen.findByText('All orders assigned')).toBeInTheDocument();
    expect(mockFetchZoneOrders).toHaveBeenCalledWith(1);
  });

  it('a failed zone-orders read shows the error/retry state, never the empty state (read error != empty data)', async () => {
    const user = userEvent.setup();
    // Rejects every call (not just-once) so the assertion below is robust to
    // exactly how many times react-query invokes the query function before
    // settling — only switched to succeed right before the retry click.
    mockFetchZoneOrders.mockRejectedValue(new Error('network down'));
    renderPage();

    await screen.findByText('Morning Run');
    await user.click(screen.getByRole('button', { name: /north zone/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument());
    expect(screen.queryByText('All orders assigned')).not.toBeInTheDocument();

    mockFetchZoneOrders.mockResolvedValue({ orders: [poolOrder()] });
    await user.click(screen.getByRole('button', { name: /retry/i }));
    expect(await screen.findByText('Nadia Farouk')).toBeInTheDocument();
  });

  it('authoritative action callback wiring: Add Trip opens the real trip form drawer for the active zone', async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Morning Run');
    await user.click(screen.getByRole('button', { name: /add trip/i }));

    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });

  it("authoritative action callback wiring: a trip's Auto-fill action calls the real mutation for that trip", async () => {
    const user = userEvent.setup();
    mockAutoFillTrip.mockResolvedValue({ trip: trip(), assigned_orders: [], assigned_count: 2 });
    renderPage();

    await screen.findByText('Morning Run');
    await user.click(screen.getByRole('button', { name: /auto-fill/i }));

    await waitFor(() => expect(mockAutoFillTrip).toHaveBeenCalledWith('trip-1'));
  });
});

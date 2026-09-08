import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { AssignmentTab } from './assignment-tab';
import type { GroupTrip, SlotSummary } from '@/features/logistics/distribution-workspace/types';

/**
 * `EntityTable` renders BOTH a mobile-card view (`block lg:hidden`) and a
 * desktop-table view (`hidden lg:block`) at once, toggled by CSS alone — in
 * JSDOM (no stylesheet loaded) both are simultaneously "visible" to Testing
 * Library, so every row's text genuinely appears twice. These helpers assert
 * presence/absence tolerant of that duplication rather than assuming a single
 * match, which is a fact about the shared component, not about this test.
 */
function expectPresent(text: string) {
  expect(screen.getAllByText(text).length).toBeGreaterThan(0);
}
function expectAbsent(text: string) {
  expect(screen.queryAllByText(text)).toHaveLength(0);
}

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §6/§21 — Assignment went from an honest
 * deep-link-only gap card (Task 001 — no bulk vehicle/driver source existed)
 * to a real table backed by the new `slotsTransportSummary` endpoint. These
 * tests protect: (1) real vehicle/driver/status render when present, (2)
 * "needs assignment" is derived from a real absence (no vehicle OR no
 * driver OR zero trips), never a fabricated flag, (3) a capacity-split
 * Group (>1 Trip) shows a "+N" indicator instead of silently dropping the
 * extra Trips.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown) => {
      if (typeof selector !== 'function') return String(selector);
      const path: string[] = [];
      const probe: unknown = new Proxy(
        {},
        { get(_t, prop): unknown { path.push(String(prop)); return probe; } },
      );
      (selector as (p: unknown) => unknown)(probe);
      return path[path.length - 1] ?? '';
    },
  }),
}));

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}));

const mockOrgContext = vi.fn();
vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => mockOrgContext(),
}));

const mockWindow = vi.fn();
const mockTransport = vi.fn();
vi.mock('@/features/logistics/distribution-workspace/hooks/use-distribution-workspace', () => ({
  useCurrentDistributionWindow: () => mockWindow(),
  useSlotsTransportSummary: () => mockTransport(),
}));

function slot(overrides: Partial<SlotSummary> = {}): SlotSummary {
  return {
    slot_id: 's-1',
    code: 'DG-0001',
    name: null,
    warehouse_id: 'wh-1',
    zone_ids: [],
    zone_names: [],
    zones_count: 2,
    orders_count: 5,
    products_count: 0,
    total_value: 0,
    paid_orders: 0,
    unpaid_orders: 0,
    status: 'draft',
    capacity_orders: null,
    capacity_stops: null,
    capacity_weight_kg: null,
    capacity_volume_m3: null,
    is_over_capacity: false,
    is_warning: false,
    ...overrides,
  } as SlotSummary;
}

function trip(overrides: Partial<GroupTrip> = {}): GroupTrip {
  return {
    trip_id: 't-1',
    trip_number: 'TRP-0001',
    name: null,
    status: 'loading',
    capacity: 60,
    orders_count: 5,
    finalized_at: '2026-09-08T00:00:00Z',
    dispatched_at: null,
    driver_vehicle_assignment_id: 1,
    vehicle: { id: 1, plate_number: 'ABC-123', name: null },
    driver: { id: 1, full_name: 'Ahmed Hassan', mobile: null },
    remaining_capacity: 55,
    ...overrides,
  };
}

function mockReady(slots: SlotSummary[], transport: Record<string, GroupTrip[]>) {
  mockOrgContext.mockReturnValue({ activeWarehouseId: 'wh-1' });
  mockWindow.mockReturnValue({
    data: { resolution: 'resolved', window: { id: 'w-1' }, slots },
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
  });
  mockTransport.mockReturnValue({ data: transport, isLoading: false, isError: false, refetch: vi.fn() });
}

describe('AssignmentTab', () => {
  it('renders a real vehicle and driver when a Group has an assigned Trip', () => {
    const s = slot({ slot_id: 's-1', code: 'DG-0001' });
    mockReady([s], { 's-1': [trip()] });

    render(<AssignmentTab />);

    expectPresent('ABC-123');
    expectPresent('Ahmed Hassan');
  });

  it('marks a Group with zero Trips as needing assignment, never showing a fabricated vehicle', () => {
    const s = slot({ slot_id: 's-2', code: 'DG-0002' });
    mockReady([s], { 's-2': [] });

    render(<AssignmentTab />);

    expectPresent('notAssigned');
    expectPresent('needsAssignment');
  });

  it('marks a Group whose Trip has a vehicle but no driver as needing assignment', () => {
    const s = slot({ slot_id: 's-3', code: 'DG-0003' });
    mockReady([s], { 's-3': [trip({ driver: null })] });

    render(<AssignmentTab />);

    expectPresent('needsAssignment');
  });

  it('shows a real "+N" indicator for a capacity-split Group instead of hiding the extra Trips', () => {
    const s = slot({ slot_id: 's-4', code: 'DG-0004' });
    mockReady([s], {
      's-4': [
        trip({ trip_id: 't-a', vehicle: { id: 1, plate_number: 'AAA-111', name: null } }),
        trip({ trip_id: 't-b', vehicle: { id: 2, plate_number: 'BBB-222', name: null } }),
      ],
    });

    render(<AssignmentTab />);

    // First trip's vehicle is shown, plus a real "+1" — not "AAA-111" alone,
    // which would silently hide the second Trip's own assignment.
    expectPresent('AAA-111');
    expectPresent('+1');
  });

  it('shows the fully-assigned state when every Trip on a Group has both vehicle and driver', () => {
    const s = slot({ slot_id: 's-5', code: 'DG-0005' });
    mockReady([s], { 's-5': [trip()] });

    render(<AssignmentTab />);

    expectPresent('assigned');
    expectAbsent('needsAssignment');
  });

  it('shows a distinct "no planning window" state rather than an empty table', () => {
    mockOrgContext.mockReturnValue({ activeWarehouseId: 'wh-1' });
    mockWindow.mockReturnValue({
      data: { resolution: 'no_planning_window', window: null, slots: [] },
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    });
    mockTransport.mockReturnValue({ data: undefined, isLoading: false, isError: false, refetch: vi.fn() });

    render(<AssignmentTab />);

    expectPresent('noWindow');
  });
});

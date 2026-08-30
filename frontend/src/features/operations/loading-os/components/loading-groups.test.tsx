import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { LoadingGroupDetail, LoadingGroupList } from './loading-groups';
import type { LoadingGroupSummary, LoadingGroupTransport } from '../types/loading-os';

/**
 * TASK-LOADING-GROUP-GRAIN-READ-AND-EXECUTION-UX-002 — the states a browser was meant
 * to confirm, pinned as tests instead.
 *
 * ┌─ THE TWO THAT MATTER MOST ───────────────────────────────────────────────┐
 * │ 1. A READ FAILURE MUST NOT RENDER AS AN ABSENCE. If the manifest cannot   │
 * │    be read, the screen says "Read unavailable" — never "Not assigned",     │
 * │    which would be a false claim about the business rather than an honest   │
 * │    one about the read.                                                    │
 * │ 2. PREPARED IS NOT LOADED. A fully prepared product that never went onto   │
 * │    a vehicle shows Loaded 0 and Remaining = Required.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

/** i18n stubbed to the key path, so assertions read against stable identifiers. */
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown) => {
      if (typeof selector !== 'function') {
        return String(selector);
      }

      const path: string[] = [];
      const probe: unknown = new Proxy(
        {},
        {
          get(_t, prop): unknown {
            path.push(String(prop));
            return probe;
          },
        },
      );

      (selector as (p: unknown) => unknown)(probe);

      return path[path.length - 1] ?? '';
    },
  }),
}));

const useLoadingGroup = vi.fn();
const startLoadingMutate = vi.fn();
const startLoadingState = { mutate: startLoadingMutate, isPending: false, isError: false, error: null };

const confirmLoadedMutate = vi.fn();
const resolveAdjustmentMutate = vi.fn();
const confirmLoadedState = {
  mutate: confirmLoadedMutate,
  isPending: false,
  isError: false,
  error: null,
};
const resolveAdjustmentState = {
  mutate: resolveAdjustmentMutate,
  isPending: false,
  isError: false,
  error: null,
};

vi.mock('../hooks/use-loading-os', () => ({
  useLoadingGroup: (slotId: string | null) => useLoadingGroup(slotId),
  useStartLoading: () => startLoadingState,
  useConfirmLoaded: () => confirmLoadedState,
  useResolveAdjustment: () => resolveAdjustmentState,
}));

const NO_TRANSPORT: LoadingGroupTransport = {
  trip: null,
  vehicle: null,
  driver: null,
  has_loading_assignment: false,
  loading_assignment_status: null,
};

/** A server refusal, echoed verbatim by the panel. Not UI copy — it comes from the API. */
const SERVER_REFUSAL = 'GroupLoadingContextService: no vehicle and driver for DG-001';

function group(over: Partial<LoadingGroupSummary> = {}): LoadingGroupSummary {
  return {
    slot_id: 's-1',
    code: 'DG-001',
    name: null,
    warehouse_id: 'w-1',
    zone_names: ['Maadi'],
    orders_count: 8,
    products_count: 2,
    transport: NO_TRANSPORT,
    ...over,
  };
}

function detail(
  products: unknown[],
  transport: LoadingGroupTransport = NO_TRANSPORT,
  totals?: unknown,
) {
  return {
    group: { slot_id: 's-1', code: 'DG-001', name: null, warehouse_id: 'w-1', window_id: 'win-1' },
    transport,
    totals: totals ?? { required: 10, prepared: 10, loaded: 0, remaining: 10, over_prepared: 0 },
    products,
  };
}

const HONEY = {
  product_id: 'p-1',
  product_name: 'Honey Jar 250g',
  product_sku: 'HNY-250',
  unit_code: null,
  unit_symbol: null,
  quantity_required: 10,
  quantity_prepared: 10,
  quantity_loaded: 0,
  quantity_remaining: 10,
  over_prepared_qty: 0,
  loading_status: null,
  // Custody fields. Defaults describe a product nothing has happened to yet:
  // `quantity_driver_received: null` is "not counted", never a counted zero.
  loading_task_id: null,
  warehouse_confirmed_at: null,
  warehouse_confirmed_by: null,
  quantity_driver_received: null,
  driver_confirmed_at: null,
  driver_confirmed_by: null,
  workflow_state: 'pending_loading' as const,
  open_adjustment: null,
};

const READY_TRANSPORT: LoadingGroupTransport = {
  trip: { trip_id: 't-1', trip_number: 'TRP-001', status: 'loading' },
  vehicle: { plate_number: '1336', name: null },
  driver: { full_name: 'OSAMA FAYEZ AHEMD', mobile: null },
  has_loading_assignment: false,
  loading_assignment_status: null,
};

beforeEach(() => {
  useLoadingGroup.mockReset();
  startLoadingMutate.mockReset();
  startLoadingState.isPending = false;
  startLoadingState.isError = false;
  useLoadingGroup.mockReturnValue({ data: undefined, isLoading: false, isError: false });
});

describe('LoadingGroupList', () => {
  const noop = () => {};

  // STATE A — the contract: visible with no transport at all.
  it('lists a group that has no vehicle, driver or trip', () => {
    render(
      <LoadingGroupList
        groups={[group()]}
        selectedSlotId={null}
        onSelect={noop}
        isLoading={false}
        isError={false}
        hasWindow
      />,
    );

    expect(screen.getByTestId('loading-group-DG-001')).toBeInTheDocument();
    expect(screen.getByTestId('loading-group-DG-001')).toHaveTextContent('planningOnly');
  });

  // STATE B — vehicle and driver named.
  it('shows the vehicle and driver when both are assigned', () => {
    render(
      <LoadingGroupList
        groups={[
          group({
            // Vehicle + driver assigned but loading NOT started yet — the genuine
            // "Ready to load" state. An existing assignment would mean it had started.
            transport: READY_TRANSPORT,
          }),
        ]}
        selectedSlotId={null}
        onSelect={noop}
        isLoading={false}
        isError={false}
        hasWindow
      />,
    );

    const card = screen.getByTestId('loading-group-DG-001');
    expect(card).toHaveTextContent('readyToLoad');
    expect(card).toHaveTextContent('1336');
    expect(card).toHaveTextContent('OSAMA FAYEZ AHEMD');
  });

  // The two empty states stay distinct — "no window" is not "no groups".
  it('distinguishes no-window from no-groups', () => {
    const { rerender } = render(
      <LoadingGroupList
        groups={[]}
        selectedSlotId={null}
        onSelect={noop}
        isLoading={false}
        isError={false}
        hasWindow={false}
      />,
    );
    expect(screen.getByTestId('loading-groups-no-window')).toBeInTheDocument();

    rerender(
      <LoadingGroupList
        groups={[]}
        selectedSlotId={null}
        onSelect={noop}
        isLoading={false}
        isError={false}
        hasWindow
      />,
    );
    expect(screen.getByTestId('loading-groups-empty')).toBeInTheDocument();
  });

  it('reports a failed list read as an error, not as an empty cycle', () => {
    render(
      <LoadingGroupList
        groups={[]}
        selectedSlotId={null}
        onSelect={noop}
        isLoading={false}
        isError
        hasWindow
      />,
    );

    expect(screen.getByTestId('loading-groups-error')).toBeInTheDocument();
    expect(screen.queryByTestId('loading-groups-empty')).toBeNull();
  });
});

describe('LoadingGroupDetail', () => {
  it('renders products with no transport, and Loaded 0 before loading starts', () => {
    useLoadingGroup.mockReturnValue({
      data: detail([HONEY]),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    const row = screen.getByTestId('loading-product-p-1');

    // Required 10, Prepared 10, Loaded 0, Remaining 10 — Remaining is Required MINUS
    // LOADED. If it were Required − Prepared it would read 0, and this fails.
    expect(row).toHaveTextContent('Honey Jar 250g');

    // Product · SKU · Required · Prepared · Loaded · Remaining · Driver received
    const cells = Array.from(row.querySelectorAll('td')).map((c) => c.textContent);
    expect(cells.slice(0, 7)).toEqual([
      'Honey Jar 250g',
      'HNY-250',
      '10',
      '10',
      '0',
      '10',
      // The driver has not counted it — rendered as a word, never as 0.
      'notCounted',
    ]);
    expect(screen.getByTestId('state-p-1')).toHaveTextContent('statePendingLoading');
  });

  it('shows the loaded quantity and the reduced remaining once loading has started', () => {
    useLoadingGroup.mockReturnValue({
      data: detail(
        [{ ...HONEY, quantity_loaded: 6, quantity_remaining: 4, loading_status: 'loaded' }],
        NO_TRANSPORT,
        { required: 10, prepared: 10, loaded: 6, remaining: 4, over_prepared: 0 },
      ),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    const cells = Array.from(screen.getByTestId('loading-product-p-1').querySelectorAll('td')).map(
      (c) => c.textContent,
    );

    // The brief's worked example: Required 10, Prepared 10, Loaded 6, Remaining 4.
    expect(cells.slice(2, 6)).toEqual(['10', '10', '6', '4']);
    expect(screen.getByTestId('loading-group-summary')).toHaveTextContent('6');
  });

  // STATE A in the detail: named as unassigned, never invented.
  it('states driver, vehicle and trip as not assigned rather than inventing them', () => {
    useLoadingGroup.mockReturnValue({ data: detail([HONEY]), isLoading: false, isError: false });

    render(<LoadingGroupDetail slotId="s-1" />);

    const transport = screen.getByTestId('loading-group-transport');
    expect(transport).toHaveTextContent('notAssigned');
    expect(transport).toHaveTextContent('tripNotCreated');
    expect(screen.getByTestId('loading-group-execution')).toHaveTextContent('executionBlocked');
  });

  /**
   * STATE D — THE ONE THAT MATTERS.
   *
   * A failed read must NOT be rendered as "Not assigned". If this ever flips, the
   * screen has started making claims about the business from a transport outage.
   */
  it('renders a read failure as unavailable, never as "not assigned"', () => {
    useLoadingGroup.mockReturnValue({ data: undefined, isLoading: false, isError: true });

    render(<LoadingGroupDetail slotId="s-1" />);

    const transport = screen.getByTestId('loading-group-transport');
    expect(transport).toHaveTextContent('readUnavailable');
    expect(transport).not.toHaveTextContent('notAssigned');
    expect(transport).not.toHaveTextContent('tripNotCreated');
    expect(screen.getByTestId('loading-group-execution')).toHaveTextContent('executionUnknown');
  });

  it('renders the empty manifest state for a group with no loadable products', () => {
    useLoadingGroup.mockReturnValue({ data: detail([]), isLoading: false, isError: false });

    render(<LoadingGroupDetail slotId="s-1" />);

    expect(screen.getByTestId('loading-group-products-empty')).toBeInTheDocument();
  });

  // ── Start Loading ────────────────────────────────────────────────────────

  it('disables Start Loading until the group has a vehicle and driver', () => {
    useLoadingGroup.mockReturnValue({ data: detail([HONEY]), isLoading: false, isError: false });

    render(<LoadingGroupDetail slotId="s-1" />);

    expect(screen.getByTestId('loading-group-start')).toBeDisabled();
  });

  it('disables Start Loading when the execution state could not be read', () => {
    useLoadingGroup.mockReturnValue({ data: undefined, isLoading: false, isError: true });

    render(<LoadingGroupDetail slotId="s-1" />);

    // An unreadable state is NOT a ready state — it must never let a doomed (or worse,
    // duplicate) write through on an assumption.
    expect(screen.getByTestId('loading-group-start')).toBeDisabled();
  });

  it('starts loading through the certified action with the canonical ids', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');

    useLoadingGroup.mockReturnValue({
      data: detail([HONEY], READY_TRANSPORT),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    const button = screen.getByTestId('loading-group-start');
    expect(button).toBeEnabled();

    await userEvent.click(button);

    expect(startLoadingMutate).toHaveBeenCalledTimes(1);
    expect(startLoadingMutate.mock.calls[0][0]).toEqual({ windowId: 'win-1', tripId: 't-1' });
  });

  /**
   * Start Loading OPENS a session. It must not record a quantity — if it ever did,
   * Prepared/Loaded would collapse into each other on the very first click.
   */
  it('does not change any loaded quantity when loading is started', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');

    useLoadingGroup.mockReturnValue({
      data: detail([HONEY], READY_TRANSPORT),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);
    await userEvent.click(screen.getByTestId('loading-group-start'));

    const cells = Array.from(screen.getByTestId('loading-product-p-1').querySelectorAll('td')).map(
      (c) => c.textContent,
    );

    // Required 10, Prepared 10, Loaded 0, Remaining 10 — unchanged by starting.
    expect(cells.slice(2, 6)).toEqual(['10', '10', '0', '10']);
  });

  /*
   * TASK-LOADING-OPERATOR-UX-ACTION-FLOW-FIX-001 — Start Loading feedback.
   *
   * The reported defect: pressing Start Loading "did nothing". The certified action is
   * idempotent, so it returned the same session — but the screen kept saying
   * "Ready to load" and kept the button enabled, so the state was right on the server
   * and wrong on the screen.
   */
  it('replaces Start Loading with a state badge once a session is open', () => {
    useLoadingGroup.mockReturnValue({
      data: detail([HONEY], {
        ...READY_TRANSPORT,
        has_loading_assignment: true,
        loading_assignment_status: 'loading',
      }),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    // No button that implies pressing it would start something new.
    expect(screen.queryByTestId('loading-group-start')).toBeNull();
    expect(screen.getByTestId('loading-group-started')).toHaveTextContent('loadingInProgress');
    expect(screen.getByTestId('loading-group-state')).toHaveTextContent('loadingInProgress');
  });

  it('says completed — not "in progress" — once the assignment is complete', () => {
    useLoadingGroup.mockReturnValue({
      data: detail([HONEY], {
        ...READY_TRANSPORT,
        has_loading_assignment: true,
        loading_assignment_status: 'loading_complete',
      }),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    // Knowing only "an assignment exists" would have announced the wrong state here.
    expect(screen.getByTestId('loading-group-state')).toHaveTextContent('loadingCompleted');
    expect(screen.queryByTestId('loading-group-start')).toBeNull();
  });

  /*
   * Warehouse Confirm feedback: re-sending the same already-confirmed quantity was a
   * no-op the server accepted, so the screen looked inert. There is now a visible
   * acknowledgement, and nothing left to submit disables the button.
   */
  it('shows a canonical confirmation and disables Confirm when nothing would change', () => {
    useLoadingGroup.mockReturnValue({
      // Loading has started — which is when the operator may edit Loaded at all.
      data: detail(
        [
          {
            ...HONEY,
            quantity_loaded: 10,
            quantity_remaining: 0,
            warehouse_confirmed_at: '2026-08-26T10:00:00+00:00',
            workflow_state: 'awaiting_driver_confirmation' as const,
          },
        ],
        { ...READY_TRANSPORT, has_loading_assignment: true, loading_assignment_status: 'loading' },
      ),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    expect(screen.getByTestId('confirmed-at-p-1')).toBeInTheDocument();
    expect(screen.getByTestId('confirm-p-1')).toBeDisabled();
  });

  it('re-enables Confirm as soon as the operator changes the quantity', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');

    useLoadingGroup.mockReturnValue({
      // Loading has started — which is when the operator may edit Loaded at all.
      data: detail(
        [
          {
            ...HONEY,
            quantity_loaded: 10,
            quantity_remaining: 0,
            warehouse_confirmed_at: '2026-08-26T10:00:00+00:00',
            workflow_state: 'awaiting_driver_confirmation' as const,
          },
        ],
        { ...READY_TRANSPORT, has_loading_assignment: true, loading_assignment_status: 'loading' },
      ),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    const input = screen.getByTestId('loaded-input-p-1');
    await userEvent.clear(input);
    await userEvent.type(input, '8');

    // A genuine revision is still submittable — the disable is "nothing to do", not a lock.
    expect(screen.getByTestId('confirm-p-1')).toBeEnabled();
  });

  it('surfaces the server refusal verbatim when starting fails', () => {
    startLoadingState.isError = true;
    (startLoadingState as { error: unknown }).error = {
      response: { data: { message: SERVER_REFUSAL } },
    };

    useLoadingGroup.mockReturnValue({
      data: detail([HONEY], READY_TRANSPORT),
      isLoading: false,
      isError: false,
    });

    render(<LoadingGroupDetail slotId="s-1" />);

    expect(screen.getByTestId('loading-group-start-error')).toHaveTextContent(SERVER_REFUSAL);
  });
});

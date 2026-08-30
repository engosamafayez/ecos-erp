import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

import type { GroupFleetOptions } from '../types';

/**
 * TASK-DISTRIBUTION-VEHICLE-DRIVER-PAIRING-FILTER-FIX-001 (behaviour) +
 * TASK-DISTRIBUTION-VEHICLE-DRIVER-TRIP-FINAL-UX-003 (card-first presentation).
 *
 * The Driver selector DEPENDS on the Vehicle: only drivers actively paired to the
 * chosen vehicle may be offered. The SERVICE is mocked and the real React-Query
 * pipeline runs. The assignment form now opens behind "Assign Vehicle & Driver"
 * (the current assignment is read from `getGroupTrips`, mocked here as none), so
 * each test opens the form first — the filtering logic itself is unchanged.
 */

const mockFleetOptions = vi.hoisted(() => vi.fn());
const mockAssign = vi.hoisted(() => vi.fn());
const mockGroupTrips = vi.hoisted(() => vi.fn());

vi.mock('../services/distribution-workspace-service', () => ({
  distributionWorkspaceService: {
    getGroupFleetOptions: mockFleetOptions,
    assignGroupVehicle: mockAssign,
    getGroupTrips: mockGroupTrips,
  },
}));

vi.mock('react-i18next', () => ({
  // Selector-mode t($ => $.a.b) — return the leaf key so assertions stay readable.
  useTranslation: () => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy(
        {},
        {
          get: (_t, prop: string) => {
            path.push(prop);
            return proxy;
          },
        },
      );
      (sel as (p: unknown) => unknown)(proxy);
      return path[path.length - 1] ?? '';
    },
  }),
}));

import { GroupVehicleAssignment } from './group-vehicle-assignment';

const PAIRED_DRIVER = '11111111-1111-1111-1111-111111111111';
const OTHER_DRIVER = '22222222-2222-2222-2222-222222222222';
const VEHICLE_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const VEHICLE_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

/*
 * API RESPONSE FIXTURES — they reproduce the server payload, not UI copy. The
 * component renders driver names from this data, exactly as it does in production.
 */
/* eslint-disable ecos-i18n/no-hardcoded-ui-strings */
const OPTIONS: GroupFleetOptions = {
  group_orders: 2,
  vehicles: [
    {
      id: VEHICLE_A,
      plate_number: '1336',
      name: null,
      status: 'available',
      capacity_orders: 25,
      fits_group: true,
      // Only this driver is actively paired with vehicle A.
      driver_ids: [PAIRED_DRIVER],
    },
    {
      id: VEHICLE_B,
      plate_number: '9999',
      name: null,
      status: 'available',
      capacity_orders: 25,
      fits_group: true,
      // Nobody is paired with vehicle B.
      driver_ids: [],
    },
  ],
  drivers: [
    { id: PAIRED_DRIVER, full_name: 'OSAMA FAYEZ AHEMD', driver_code: 'DRV-001', mobile: null },
    { id: OTHER_DRIVER, full_name: 'ahmed', driver_code: 'DRV-002', mobile: null },
  ],
};
/* eslint-enable ecos-i18n/no-hardcoded-ui-strings */

function renderComponent() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <GroupVehicleAssignment windowId="w-1" slotId="s-1" canPlan />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/**
 * The assignment form opens behind "Assign Vehicle & Driver" in the card-first
 * redesign. Open it before exercising the vehicle/driver selects.
 */
async function openForm(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByTestId('group-assign-open'));
  await screen.findByTestId('group-vehicle-select');
}

/** Open the vehicle dropdown and choose the option whose label contains `plate`. */
async function chooseVehicle(user: ReturnType<typeof userEvent.setup>, plate: string) {
  await user.click(screen.getByTestId('group-vehicle-select'));
  await user.click(await screen.findByText(new RegExp(plate)));
}

describe('GroupVehicleAssignment — driver depends on vehicle', () => {
  // Radix Select drives its trigger through Pointer Events, which jsdom does not
  // implement. These are environment shims, not behaviour stubs — the component
  // and its filtering logic run exactly as they do in the browser.
  beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn(() => false);
    Element.prototype.setPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
  });

  beforeEach(() => {
    vi.clearAllMocks();
    mockFleetOptions.mockResolvedValue(OPTIONS);
    // No existing assignment → the component shows the empty state with the
    // "Assign Vehicle & Driver" opener; openForm() reveals the certified form.
    mockGroupTrips.mockResolvedValue({ trips: [], readiness: [] });
  });

  it('offers no driver until a vehicle is chosen', async () => {
    const user = userEvent.setup();
    renderComponent();

    await openForm(user);

    expect(screen.getByTestId('group-driver-select')).toBeDisabled();
  });

  it('CASE 1 — offers ONLY the driver paired to the selected vehicle', async () => {
    const user = userEvent.setup();
    renderComponent();

    await openForm(user);
    await chooseVehicle(user, '1336');

    await user.click(screen.getByTestId('group-driver-select'));

    // The paired driver is offered…
    expect(await screen.findByText(/OSAMA FAYEZ AHEMD/)).toBeInTheDocument();
    // …and the unrelated driver — the reported defect — is not.
    expect(screen.queryByText(/ahmed · DRV-002/)).not.toBeInTheDocument();
  });

  it('CASE 3 — states the empty case when the vehicle has no paired driver', async () => {
    const user = userEvent.setup();
    renderComponent();

    await openForm(user);
    await chooseVehicle(user, '9999');

    expect(await screen.findByTestId('group-driver-none')).toBeInTheDocument();
    expect(screen.getByTestId('group-driver-select')).toBeDisabled();
  });

  it('CASE 4 — clears a driver that the newly chosen vehicle is not paired with', async () => {
    const user = userEvent.setup();
    renderComponent();

    await openForm(user);

    // Vehicle A + its paired driver.
    await chooseVehicle(user, '1336');
    await user.click(screen.getByTestId('group-driver-select'));
    await user.click(await screen.findByText(/OSAMA FAYEZ AHEMD/));

    await waitFor(() => {
      expect(screen.getByTestId('group-driver-select')).toHaveTextContent(/OSAMA/);
    });

    // Switching to a vehicle that driver is NOT paired with must drop the stale pick.
    await chooseVehicle(user, '9999');

    await waitFor(() => {
      expect(screen.getByTestId('group-driver-select')).not.toHaveTextContent(/OSAMA/);
    });
    expect(screen.getByTestId('group-driver-none')).toBeInTheDocument();
  });
});

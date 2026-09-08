import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { DispatchExecutionPage } from './dispatch-execution-page';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §5/§22 — "Dispatch tab routing":
 * a direct URL must open the correct tab, an invalid `?tab=` must fall back
 * safely (never crash, never silently show a blank page), and Control
 * Tower/Shipping Orders/Returns & Settlement's own deep links (`?tab=
 * assignment`, `?tab=loading`, `?tab=handover`, `?tab=trips`) must all land
 * precisely. Real react-router (`MemoryRouter`), not a mocked
 * `useSearchParams` — this is specifically what a mocked hook could not
 * prove (that the URL itself, not just a hook call, drives the tab).
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

vi.mock('../components/groups-tab', () => ({ GroupsTab: () => <div data-testid="panel-groups" /> }));
vi.mock('../components/assignment-tab', () => ({ AssignmentTab: () => <div data-testid="panel-assignment" /> }));
vi.mock('../components/loading-bucket-tab', () => ({
  LoadingBucketTab: ({ bucket }: { bucket: string }) => <div data-testid={`panel-loading-${bucket}`} />,
}));
vi.mock('../components/active-trips-tab', () => ({ ActiveTripsTab: () => <div data-testid="panel-trips" /> }));

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/logistics/dispatch-execution" element={<DispatchExecutionPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('DispatchExecutionPage tab routing', () => {
  it('opens Groups by default with no ?tab= param', () => {
    renderAt('/logistics/dispatch-execution');

    expect(screen.getByTestId('panel-groups')).toBeVisible();
  });

  it('opens Assignment directly from ?tab=assignment (Control Tower\'s deep link)', () => {
    renderAt('/logistics/dispatch-execution?tab=assignment');

    expect(screen.getByTestId('panel-assignment')).toBeVisible();
  });

  it('opens the Loading bucket directly from ?tab=loading', () => {
    renderAt('/logistics/dispatch-execution?tab=loading');

    expect(screen.getByTestId('panel-loading-current_actionable')).toBeVisible();
  });

  it('opens the Handover bucket directly from ?tab=handover', () => {
    renderAt('/logistics/dispatch-execution?tab=handover');

    expect(screen.getByTestId('panel-loading-waiting_driver_confirmation')).toBeVisible();
  });

  it('opens Active Trips directly from ?tab=trips', () => {
    renderAt('/logistics/dispatch-execution?tab=trips');

    expect(screen.getByTestId('panel-trips')).toBeVisible();
  });

  it('falls back safely to Groups for an invalid ?tab= value, without crashing', () => {
    renderAt('/logistics/dispatch-execution?tab=not-a-real-tab');

    expect(screen.getByTestId('panel-groups')).toBeVisible();
  });
});

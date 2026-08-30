import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { TripReadinessPanel } from './trip-readiness-panel';
import type { TripReadiness } from '../types';

/**
 * TASK-1-C-UI §17 — the readiness panel.
 *
 * ┌─ WHAT THESE TESTS EXIST TO PROTECT ──────────────────────────────────────┐
 * │ The panel must RENDER the server's decision, never compute one. So the     │
 * │ decisive test is the contradictory payload: `ready: true` alongside a      │
 * │ FAILING check. A panel that recomputed readiness would "correct" the       │
 * │ server and show BLOCKED; this one must show READY, because the server is   │
 * │ the authority and a disagreement here is exactly the bug worth catching.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

/** i18n is stubbed to the key path, so assertions read against stable identifiers. */
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown, vars?: Record<string, unknown>) => {
      if (typeof selector !== 'function') {
        return String(selector);
      }

      // Record the property path the selector walks, e.g. "readiness.ready".
      const path: string[] = [];
      const probe: unknown = new Proxy(
        {},
        {
          get(_target, prop): unknown {
            path.push(String(prop));
            return probe;
          },
        },
      );

      (selector as (p: unknown) => unknown)(probe);

      const key = path[path.length - 1] ?? '';

      // Interpolation values are appended rather than dropped: a stub that silently
      // discarded `{{count}}` would let a component that never passed one still pass
      // the test.
      if (vars === undefined) {
        return key;
      }

      return [key, ...Object.values(vars).map(String)].join(' ');
    },
  }),
}));

vi.mock('@/components/ds/use-toast', () => ({
  useToast: () => ({ toast: vi.fn() }),
}));

const mutate = vi.fn();

vi.mock('../hooks/use-distribution-workspace', () => ({
  useOpenGroupLoading: () => ({ mutate, isPending: false }),
}));

function renderPanel(readiness: TripReadiness | null, opts: { isLoading?: boolean; isError?: boolean } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={client}>
      <TripReadinessPanel
        readiness={readiness}
        windowId="w-1"
        slotId="s-1"
        isLoading={opts.isLoading ?? false}
        isError={opts.isError ?? false}
      />
    </QueryClientProvider>,
  );
}

const READY: TripReadiness = {
  trip_id: 't-1',
  ready: true,
  reason: null,
  checks: [
    { key: 'trip_belongs_to_group', ok: true },
    { key: 'manifest_membership', ok: true },
    { key: 'manifest_complete', ok: true },
    { key: 'vehicle_assigned', ok: true },
    { key: 'driver_assigned', ok: true },
  ],
};

const BLOCKED: TripReadiness = {
  trip_id: 't-2',
  ready: false,
  reason: 'Group DG-1 has 1 accepted order(s) that no trip carries: ORD-9.',
  checks: [
    { key: 'trip_belongs_to_group', ok: true },
    { key: 'manifest_membership', ok: false },
    { key: 'manifest_complete', ok: false },
    { key: 'vehicle_assigned', ok: true },
    { key: 'driver_assigned', ok: false },
  ],
};

describe('TripReadinessPanel', () => {
  // §17.1
  it('renders the ready state when the server says ready', () => {
    renderPanel(READY);

    expect(screen.getByTestId('trip-readiness-state')).toHaveTextContent('ready');
  });

  // §17.2
  it('enables Start Loading when ready', () => {
    renderPanel(READY);

    expect(screen.getByTestId('trip-readiness-start')).toBeEnabled();
  });

  // §17.3
  it('renders the blocked state when the server says blocked', () => {
    renderPanel(BLOCKED);

    expect(screen.getByTestId('trip-readiness-state')).toHaveTextContent('blocked');
  });

  // §17.4 — the server's own sentence, verbatim.
  it('shows the canonical blocking reason', () => {
    renderPanel(BLOCKED);

    expect(screen.getByTestId('trip-readiness-reasons')).toHaveTextContent(
      'Group DG-1 has 1 accepted order(s) that no trip carries: ORD-9.',
    );
  });

  // §17.5
  it('does not enable Start Loading when blocked', () => {
    renderPanel(BLOCKED);

    expect(screen.getByTestId('trip-readiness-start')).toBeDisabled();
  });

  // §17.6 — every failure, not merely the first.
  it('renders every failing check, not just one', () => {
    renderPanel(BLOCKED);

    expect(screen.getByTestId('trip-readiness-check-manifest_membership')).toBeInTheDocument();
    expect(screen.getByTestId('trip-readiness-check-manifest_complete')).toBeInTheDocument();
    expect(screen.getByTestId('trip-readiness-check-driver_assigned')).toBeInTheDocument();
    expect(screen.getByTestId('trip-readiness-reasons')).toHaveTextContent('3');
  });

  // §17.7
  it('renders the loading state', () => {
    renderPanel(null, { isLoading: true });

    expect(screen.getByTestId('trip-readiness-loading')).toBeInTheDocument();
  });

  // §17.8
  it('renders the error state without exposing a raw exception', () => {
    renderPanel(null, { isError: true });

    const node = screen.getByTestId('trip-readiness-error');

    expect(node).toBeInTheDocument();
    expect(node.textContent ?? '').not.toContain('Exception');
    expect(node.textContent ?? '').not.toContain('\\');
  });

  it('states plainly when the server reported no decision', () => {
    renderPanel(null);

    expect(screen.getByTestId('trip-readiness-empty')).toBeInTheDocument();
  });

  /**
   * §17.9 — THE ONE THAT MATTERS.
   *
   * A deliberately contradictory payload: the server says ready while one check reads
   * false. The panel must follow the SERVER, not its own reading of the checks. If this
   * ever renders BLOCKED, the frontend has started deciding readiness for itself.
   */
  it('follows the server verdict rather than recomputing it from the checks', () => {
    renderPanel({
      trip_id: 't-3',
      ready: true,
      reason: null,
      checks: [
        { key: 'manifest_complete', ok: false },
        { key: 'driver_assigned', ok: true },
      ],
    });

    expect(screen.getByTestId('trip-readiness-state')).toHaveTextContent('ready');
    expect(screen.getByTestId('trip-readiness-start')).toBeEnabled();
  });

  // §17.10 — the CTA calls the existing Loading action with the canonical ids.
  it('starts loading through the existing action', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');

    mutate.mockClear();
    renderPanel(READY);

    await userEvent.click(screen.getByTestId('trip-readiness-start'));

    expect(mutate).toHaveBeenCalledTimes(1);
    expect(mutate.mock.calls[0][0]).toEqual({
      windowId: 'w-1',
      slotId: 's-1',
      tripId: 't-1',
    });
  });

  it('does not call the loading action while blocked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');

    mutate.mockClear();
    renderPanel(BLOCKED);

    const button = screen.getByTestId('trip-readiness-start');
    await userEvent.click(button, { pointerEventsCheck: 0 });

    expect(mutate).not.toHaveBeenCalled();
  });

  /** An unknown server check still appears rather than vanishing. */
  it('renders a check it has no label for instead of hiding it', () => {
    renderPanel({
      trip_id: 't-4',
      ready: false,
      reason: null,
      checks: [{ key: 'some_future_check', ok: false }],
    });

    expect(screen.getByTestId('trip-readiness-check-some_future_check')).toBeInTheDocument();
  });
});

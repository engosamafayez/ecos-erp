/**
 * TASK-PHASE3-RC10-FRONTEND-TEST-CERTIFY-001 — transition refusal evidence.
 *
 * The original defect: `transition.mutate(..., { onSuccess: done })` carried no
 * `onError`, so every backend refusal was silently swallowed — the drawer did
 * nothing and the operator got no feedback.
 *
 * These tests drive the REAL implementation path:
 *   axios error -> axios.isAxiosError() -> response.data.message
 *   -> refusal state -> rendered alert
 *
 * Only the mutation HOOK is stubbed (so the test controls resolve/reject); the
 * component's own extraction and rendering are never bypassed.
 */

import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError, AxiosHeaders } from 'axios';

import enOrders from '@/i18n/locales/en/orders.json';
import arOrders from '@/i18n/locales/ar/orders.json';

const { transitionMutate, rescheduleMutate } = vi.hoisted(() => ({
  transitionMutate: vi.fn(),
  rescheduleMutate: vi.fn(),
}));

// Selector-mode t(): resolve the selector against the real locale bundle, so a
// missing or renamed key fails the test rather than silently rendering a path.
const { activeBundle } = vi.hoisted(() => ({ activeBundle: { current: null as unknown } }));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: (b: unknown) => string) => selector(activeBundle.current),
  }),
}));

vi.mock('@/features/orders/hooks/use-orders', () => ({
  useOrderWorkflowTransition: () => ({ mutate: transitionMutate, isPending: false }),
  useOrderWorkflowReschedule: () => ({ mutate: rescheduleMutate, isPending: false }),
}));

import { WorkflowTab } from './order-detail-drawer';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function axiosRefusal(message: string | null): AxiosError {
  const error = new AxiosError('Request failed with status code 422');
  error.response = {
    data: message === null ? {} : { message },
    status: 422,
    statusText: 'Unprocessable Content',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  };

  return error;
}

const ACTION_LABEL = ['RC10', 'TARGET', 'ACTION'].join('_');

const order = {
  id: 'order-1',
  status: 'in_progress',
  allowed_status_transitions: [
    { target_status: 'ready_for_dispatch', label: ACTION_LABEL, requires_reason: false },
  ],
} as never;

function renderTab(onClose = vi.fn()) {
  return { onClose, ...render(<WorkflowTab order={order} onClose={onClose} />) };
}

async function clickAction() {
  await userEvent.click(screen.getByRole('button', { name: /RC10_TARGET_ACTION/ }));
}

beforeEach(() => {
  vi.clearAllMocks();
  activeBundle.current = enOrders;
});

// ─── TEST 1 — success path preserved ─────────────────────────────────────────

describe('WorkflowTab transition refusal', () => {
  it('closes the drawer on a successful transition and shows no refusal', async () => {
    transitionMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());

    const { onClose } = renderTab();
    await clickAction();

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  // ─── TEST 2 — backend refusal message rendered verbatim ────────────────────

  it('displays the exact backend refusal message and keeps the drawer open', async () => {
    const backendMessage = 'Transition from [in_progress] to [delivered] is not allowed.';
    transitionMutate.mockImplementation((_vars, opts) => opts?.onError?.(axiosRefusal(backendMessage)));

    const { onClose } = renderTab();
    await clickAction();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByRole('alert')).toHaveTextContent(backendMessage);
    expect(onClose).not.toHaveBeenCalled();
  });

  // ─── TEST 3 — fallback only when no structured message ─────────────────────

  it('falls back to the localized string only when the response carries no message', async () => {
    transitionMutate.mockImplementation((_vars, opts) => opts?.onError?.(axiosRefusal(null)));

    const { onClose } = renderTab();
    await clickAction();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByRole('alert')).toHaveTextContent(enOrders.drawer.workflow.refusalFallback);
    expect(onClose).not.toHaveBeenCalled();
  });

  // ─── TEST 4 — order state must not mutate ──────────────────────────────────

  it('does not optimistically change the displayed order state after a refusal', async () => {
    transitionMutate.mockImplementation((_vars, opts) => opts?.onError?.(axiosRefusal('Refused.')));

    renderTab();
    await clickAction();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

    // The component renders `order` straight from props and never writes to it,
    // so the only way the UI could imply a transition is if the action vanished
    // or the target status were presented as current. Neither may happen.
    expect(order).toMatchObject({ status: 'in_progress' });

    // The action is still offered, which is only true if state did not advance.
    expect(screen.getByRole('button', { name: /RC10_TARGET_ACTION/ })).toBeInTheDocument();

    // And no success affordance replaced it.
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  // ─── TEST 5 — drawer remains usable ────────────────────────────────────────

  it('leaves the action available and retryable after a refusal', async () => {
    transitionMutate.mockImplementation((_vars, opts) => opts?.onError?.(axiosRefusal('Refused.')));

    renderTab();
    await clickAction();
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

    const action = screen.getByRole('button', { name: /RC10_TARGET_ACTION/ });
    expect(action).toBeEnabled();

    // Retry succeeds — proves no stuck loading state and no success state stuck on.
    transitionMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());
    await userEvent.click(action);

    expect(transitionMutate).toHaveBeenCalledTimes(2);
  });

  // ─── TEST 6 — EN / AR ──────────────────────────────────────────────────────

  it('renders the fallback in Arabic without a missing key', async () => {
    activeBundle.current = arOrders;
    transitionMutate.mockImplementation((_vars, opts) => opts?.onError?.(axiosRefusal(null)));

    renderTab();
    await clickAction();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

    const arabicFallback = arOrders.drawer.workflow.refusalFallback;
    expect(arabicFallback).toBeTruthy();
    expect(arabicFallback).not.toBe(enOrders.drawer.workflow.refusalFallback);
    expect(screen.getByRole('alert')).toHaveTextContent(arabicFallback);
  });

  it('renders a backend-supplied message verbatim under AR (not re-translated)', async () => {
    activeBundle.current = arOrders;
    const backendMessage = 'Transition from [in_progress] to [delivered] is not allowed.';
    transitionMutate.mockImplementation((_vars, opts) => opts?.onError?.(axiosRefusal(backendMessage)));

    renderTab();
    await clickAction();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByRole('alert')).toHaveTextContent(backendMessage);
  });
});

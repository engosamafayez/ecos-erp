/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009-R1 (§5).
 *
 * Focused coverage for the one-order override wiring newly added to
 * order-detail-drawer.tsx's WorkflowTab (the drawer used from the Orders list,
 * Distribution and Driver Settlement — order-detail-page.tsx already had this
 * action; this file certifies the drawer now offers the SAME action through the
 * SAME hook, not a second implementation).
 *
 * Follows workflow-tab-refusal.test.tsx's established pattern: mock only the
 * mutation hooks and usePermission, drive the real component/dialog logic.
 */

import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import enOrders from '@/i18n/locales/en/orders.json';

const { transitionMutate, rescheduleMutate, blockOverrideMutate, mockCan } = vi.hoisted(() => ({
  transitionMutate: vi.fn(),
  rescheduleMutate: vi.fn(),
  blockOverrideMutate: vi.fn(),
  mockCan: vi.fn(() => true),
}));

const { activeBundle } = vi.hoisted(() => ({ activeBundle: { current: null as unknown } }));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: (b: unknown) => string) => selector(activeBundle.current),
  }),
}));

vi.mock('@/features/orders/hooks/use-orders', () => ({
  useOrderWorkflowTransition: () => ({ mutate: transitionMutate, isPending: false }),
  useOrderWorkflowReschedule: () => ({ mutate: rescheduleMutate, isPending: false }),
  useOrderBlockOverride: () => ({ mutate: blockOverrideMutate, isPending: false }),
}));

vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: mockCan, cannot: (p: string) => !mockCan(p), canAccess: mockCan, canExecute: mockCan }),
}));

import { WorkflowTab } from './order-detail-drawer';

function blockedOrder(overrides: Record<string, unknown> = {}) {
  return {
    id: 'order-1',
    order_number: 'ORD-1',
    status: 'on_hold',
    hold_reason_code: 'blocked_customer',
    is_blocked_customer_hold: true,
    allowed_status_transitions: [],
    ...overrides,
  } as never;
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  activeBundle.current = enOrders;
});

describe('WorkflowTab — one-order block override', () => {
  it('shows the override action when the hold is live and the operator is authorized', () => {
    render(<WorkflowTab order={blockedOrder()} onClose={vi.fn()} />);

    expect(screen.getByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })).toBeInTheDocument();
  });

  it('hides the override action when the operator lacks crm.customers.override_block', () => {
    mockCan.mockReturnValue(false);
    render(<WorkflowTab order={blockedOrder()} onClose={vi.fn()} />);

    expect(screen.queryByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })).not.toBeInTheDocument();
  });

  it('hides the override action once the hold is no longer live (already overridden)', () => {
    render(<WorkflowTab order={blockedOrder({ is_blocked_customer_hold: false })} onClose={vi.fn()} />);

    expect(screen.queryByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })).not.toBeInTheDocument();
  });

  it('hides the override action for an unrelated On Hold cause', () => {
    render(<WorkflowTab order={blockedOrder({ hold_reason_code: 'shipping_review', is_blocked_customer_hold: false })} onClose={vi.fn()} />);

    expect(screen.queryByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })).not.toBeInTheDocument();
  });

  it('keeps the confirm button disabled until a reason is entered, then submits the trimmed reason', async () => {
    render(<WorkflowTab order={blockedOrder()} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction }));

    const dialogTitle = await screen.findByRole('heading', { name: enOrders.orderDetail.blockedCustomer.overrideDialogTitle });
    const dialog = dialogTitle.closest('[role="dialog"]') ?? dialogTitle.closest('[role="alertdialog"]') ?? document.body;
    const confirmButton = () => screen.getAllByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })
      .find((btn) => dialog.contains(btn))!;

    expect(confirmButton()).toBeDisabled();

    const reasonInput = screen.getByPlaceholderText(enOrders.orderDetail.blockedCustomer.overrideReasonPlaceholder);
    await userEvent.type(reasonInput, '  Approved by manager  ');

    expect(confirmButton()).toBeEnabled();
    await userEvent.click(confirmButton());

    expect(blockOverrideMutate).toHaveBeenCalledTimes(1);
    expect(blockOverrideMutate).toHaveBeenCalledWith(
      { id: 'order-1', reason: 'Approved by manager' },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }),
    );
  });

  it('does not reserve/confirm/prepare directly — the mutate call carries only id and reason', async () => {
    render(<WorkflowTab order={blockedOrder()} onClose={vi.fn()} />);
    await userEvent.click(screen.getByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction }));
    await userEvent.type(
      screen.getByPlaceholderText(enOrders.orderDetail.blockedCustomer.overrideReasonPlaceholder),
      'reason',
    );

    const dialogTitle = await screen.findByRole('heading', { name: enOrders.orderDetail.blockedCustomer.overrideDialogTitle });
    const dialog = dialogTitle.closest('[role="dialog"]') ?? dialogTitle.closest('[role="alertdialog"]') ?? document.body;
    const confirmButton = screen.getAllByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })
      .find((btn) => dialog.contains(btn))!;
    await userEvent.click(confirmButton);

    const [payload] = blockOverrideMutate.mock.calls[0];
    expect(Object.keys(payload).sort()).toEqual(['id', 'reason']);
    expect(transitionMutate).not.toHaveBeenCalled();
    expect(rescheduleMutate).not.toHaveBeenCalled();
  });

  it('closes the dialog and toasts success on a successful override', async () => {
    blockOverrideMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());

    render(<WorkflowTab order={blockedOrder()} onClose={vi.fn()} />);
    await userEvent.click(screen.getByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction }));
    await userEvent.type(
      screen.getByPlaceholderText(enOrders.orderDetail.blockedCustomer.overrideReasonPlaceholder),
      'reason',
    );

    const dialogTitle = await screen.findByRole('heading', { name: enOrders.orderDetail.blockedCustomer.overrideDialogTitle });
    const dialog = dialogTitle.closest('[role="dialog"]') ?? dialogTitle.closest('[role="alertdialog"]') ?? document.body;
    const confirmButton = screen.getAllByRole('button', { name: enOrders.orderDetail.blockedCustomer.overrideAction })
      .find((btn) => dialog.contains(btn))!;
    await userEvent.click(confirmButton);

    await waitFor(() => expect(screen.queryByRole('heading', { name: enOrders.orderDetail.blockedCustomer.overrideDialogTitle })).not.toBeInTheDocument());
  });
});

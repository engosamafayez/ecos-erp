/**
 * TASK-PROC-PURCHASING-PHASE2-PART1-PURCHASE-MATERIAL-RECEIVING-UI-001.
 *
 * The gap this UI closed: the backend has accepted receipts anchored to a Purchase Material
 * line since Phase 2 Part 1, but the only receipt form in the app demanded a Purchase Order,
 * so the certified path was unreachable. These tests pin the two properties that make the new
 * tab correct — and that a future refactor could quietly break:
 *
 *   1. Required / Received / Remaining are RENDERED FROM THE SERVER, never recomputed here.
 *   2. The submitted payload anchors on `purchase_material_line_id` and carries NO
 *      `purchase_order_id` / `purchase_order_line_id`.
 *
 * The i18n mock resolves selectors against the REAL locale bundles, so a missing or renamed
 * key fails the test instead of silently rendering a key path — and the Arabic bundle is
 * exercised too, which is where a half-added translation would otherwise hide.
 */

import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

import enPm from '@/i18n/locales/en/purchase-materials.json';
import arPm from '@/i18n/locales/ar/purchase-materials.json';

const { createMutate, postMutate, activeBundle, canRef } = vi.hoisted(() => ({
  createMutate: vi.fn(),
  postMutate: vi.fn(),
  activeBundle: { current: null as unknown },
  canRef: { current: (): boolean => true },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: (b: unknown) => string) => selector(activeBundle.current),
  }),
}));

vi.mock('@/features/goods-receipts/hooks/use-goods-receipts', () => ({
  useCreateGoodsReceipt: () => ({ mutateAsync: createMutate, isPending: false }),
  usePostGoodsReceipt: () => ({ mutateAsync: postMutate, isPending: false }),
}));

vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: () => canRef.current() }),
}));

// Empty on purpose: the supplier LABEL is a display lookup and is verified in the browser.
// What matters here is that the line's `supplier_id` gates submission (RD-1), which the
// "refuses a line whose supplier was never selected" case covers.
vi.mock('@/features/purchase-orders/hooks/use-supplier-options', () => ({
  useSupplierOptions: () => ({ data: [], isLoading: false }),
}));

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

// The review dialog itself is not under test — render its content inline so the confirm
// button is reachable without pulling in the `common` namespace.
vi.mock('@/components/crud', () => ({
  ConfirmDialog: ({
    open,
    title,
    description,
    confirmLabel,
    onConfirm,
  }: {
    open: boolean;
    title: string;
    description?: ReactNode;
    confirmLabel?: string;
    onConfirm: () => void;
  }) =>
    open ? (
      <div data-testid="review">
        <p>{title}</p>
        <div>{description}</div>
        <button type="button" data-testid="review-confirm" onClick={onConfirm}>
          {confirmLabel}
        </button>
      </div>
    ) : null,
}));

import { PurchaseMaterialReceivingTab } from './purchase-material-receiving-tab';
import type { PurchaseMaterial, PurchaseMaterialLine } from '../types/purchase-material';

function line(over: Partial<PurchaseMaterialLine> = {}): PurchaseMaterialLine {
  return {
    id: 'line-1',
    purchase_material_id: 'pm-1',
    product_id: 'prod-1',
    product: { id: 'prod-1', sku: 'PKG-JAR-250', name: 'Glass Jar 250ml', image_url: null, average_cost: null },
    requested_qty: 100,
    unit_label: 'pcs',
    notes: null,
    supplier_id: 'sup-1',
    supplier: null,
    agreed_price: null,
    agreed_qty: null,
    lead_time_days: null,
    supplier_selected_at: '2026-08-21T03:28:54+00:00',
    supplier_selected_by: '1',
    required_qty: 100,
    received_qty: 0,
    remaining_qty: 100,
    ...over,
  };
}

function material(over: Partial<PurchaseMaterial> = {}): PurchaseMaterial {
  return {
    id: 'pm-1',
    request_number: 'PM-00002',
    status: 'approved',
    status_label: 'Approved',
    warehouse_id: 'wh-1',
    warehouse: { id: 'wh-1', name: 'Main Warehouse' },
    lines: [line()],
    ...over,
  } as PurchaseMaterial;
}

beforeEach(() => {
  vi.clearAllMocks();
  activeBundle.current = enPm;
  canRef.current = () => true;
  createMutate.mockResolvedValue({ id: 'gr-1' });
  postMutate.mockResolvedValue({ id: 'gr-1' });
});

describe('receiving position comes from the server', () => {
  it('renders Required / Received / Remaining exactly as supplied', () => {
    // Deliberately inconsistent with requested_qty: if the component recomputed anything,
    // these would not survive.
    render(
      <PurchaseMaterialReceivingTab
        material={material({ lines: [line({ required_qty: 80, received_qty: 30, remaining_qty: 50 })] })}
      />,
    );

    expect(screen.getByText('80')).toBeInTheDocument();
    expect(screen.getByText('30')).toBeInTheDocument();
    expect(screen.getByText('50')).toBeInTheDocument();
  });

  it('shows the fully-received state when nothing remains', () => {
    render(
      <PurchaseMaterialReceivingTab
        material={material({ lines: [line({ received_qty: 100, remaining_qty: 0 })] })}
      />,
    );

    expect(screen.getByText(enPm.purchaseDrawer.receiving.fullyReceived)).toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  });
});

describe('quantity rules', () => {
  it('refuses a quantity greater than remaining', async () => {
    const user = userEvent.setup();
    render(<PurchaseMaterialReceivingTab material={material()} />);

    await user.type(screen.getByRole('spinbutton'), '150');

    expect(screen.getByText(enPm.purchaseDrawer.receiving.exceedsRemaining)).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: enPm.purchaseDrawer.receiving.confirmReceipt }),
    ).toBeDisabled();
  });

  it('keeps Confirm Receipt disabled until a quantity is entered', () => {
    render(<PurchaseMaterialReceivingTab material={material()} />);

    expect(
      screen.getByRole('button', { name: enPm.purchaseDrawer.receiving.confirmReceipt }),
    ).toBeDisabled();
  });
});

describe('the submitted payload', () => {
  it('anchors on the purchase-material line and never asks for a purchase order', async () => {
    const user = userEvent.setup();
    render(<PurchaseMaterialReceivingTab material={material()} />);

    await user.type(screen.getByRole('spinbutton'), '40');
    await user.click(screen.getByRole('button', { name: enPm.purchaseDrawer.receiving.confirmReceipt }));
    await user.click(screen.getByTestId('review-confirm'));

    await waitFor(() => expect(createMutate).toHaveBeenCalledTimes(1));

    const payload = createMutate.mock.calls[0][0];
    expect(payload.purchase_order_id).toBeUndefined();
    expect(payload.warehouse_id).toBe('wh-1');
    expect(payload.lines).toHaveLength(1);

    const [only] = payload.lines;
    expect(only.purchase_material_line_id).toBe('line-1');
    expect(only.purchase_order_line_id).toBeUndefined();
    expect(only.product_id).toBe('prod-1');
    expect(only.ordered_quantity).toBe(100); // the server's required_qty
    expect(only.gross_received_quantity).toBe(40);
    expect(only.net_received_quantity).toBe(40);

    // Confirm Receipt is one operator action: create then post.
    await waitFor(() => expect(postMutate).toHaveBeenCalledWith('gr-1'));
  });

  it('submits nothing when the operator has no receiving permission', () => {
    canRef.current = () => false;
    render(<PurchaseMaterialReceivingTab material={material()} />);

    expect(screen.getByText(enPm.purchaseDrawer.receiving.noPermission)).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: enPm.purchaseDrawer.receiving.confirmReceipt }),
    ).toBeDisabled();
  });

  it('refuses a line whose supplier was never selected (RD-1)', async () => {
    const user = userEvent.setup();
    render(
      <PurchaseMaterialReceivingTab
        material={material({ lines: [line({ supplier_id: null })] })}
      />,
    );

    await user.type(screen.getByRole('spinbutton'), '10');

    expect(screen.getByText(enPm.purchaseDrawer.receiving.supplierRequired)).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: enPm.purchaseDrawer.receiving.confirmReceipt }),
    ).toBeDisabled();
    expect(createMutate).not.toHaveBeenCalled();
  });
});

describe('localisation', () => {
  it('renders from the Arabic bundle too', () => {
    activeBundle.current = arPm;
    render(<PurchaseMaterialReceivingTab material={material()} />);

    // A key present in English but missing in Arabic would render undefined here.
    expect(
      screen.getByRole('button', { name: arPm.purchaseDrawer.receiving.confirmReceipt }),
    ).toBeInTheDocument();
    expect(screen.getByText(arPm.purchaseDrawer.receiving.required)).toBeInTheDocument();
    expect(screen.getByText(arPm.purchaseDrawer.receiving.remaining)).toBeInTheDocument();
  });
});

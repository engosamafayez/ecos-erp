import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { GoodsReceipt } from '@/features/goods-receipts/types/goods-receipt';

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §B.
 *
 * The real create flow that replaces the "POST /supplier-returns" placeholder. Covers
 * the two properties the ticket calls out explicitly: the return is anchored to a real
 * Goods Receipt line (never a freehand product/quantity), and the client refuses a
 * return quantity beyond what was actually received (SR-2's own ceiling, mirrored here
 * as an immediate check rather than only discovered later at Approve).
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: Record<string, unknown>) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      return opts ? `${key}:${JSON.stringify(opts)}` : key;
    },
  }),
}));

const mockCreateMutate = vi.hoisted(() => vi.fn());
vi.mock('@/features/supplier-returns/hooks/use-supplier-returns', () => ({
  useCreateSupplierReturn: () => ({ mutate: mockCreateMutate, isPending: false }),
}));

const receiptList = vi.hoisted(() => vi.fn());
const receiptGet = vi.hoisted(() => vi.fn());
vi.mock('@/features/goods-receipts/services/goods-receipts-service', () => ({
  goodsReceiptsService: { list: receiptList, get: receiptGet },
}));

const suppliersList = vi.hoisted(() => vi.fn());
vi.mock('@/features/suppliers/services/suppliers-service', () => ({
  suppliersService: { list: suppliersList },
}));

import { SupplierReturnForm } from './supplier-return-form';

function baseReceipt(overrides: Partial<GoodsReceipt> = {}): GoodsReceipt {
  return {
    id: 'gr-1',
    receipt_number: 'GR-000001',
    purchase_order_id: 'po-1',
    purchase_order: { id: 'po-1', po_number: 'PO-000001', supplier: { id: 'sup-1', name: 'Acme Supplies' } },
    is_invoice_originated: false,
    supplier_invoice: null,
    warehouse_id: 'wh-1',
    warehouse: { id: 'wh-1', code: 'WH1', name: 'Main Warehouse' },
    receipt_date: '2026-09-01',
    status: 'posted',
    notes: null,
    supplier_invoice_number: null,
    supplier_invoice_date: null,
    invoice_attachment_path: null,
    invoice_attachment_url: null,
    invoice_total_amount: 0,
    paid_amount: 0,
    outstanding_amount: 0,
    freight_amount: 0,
    tax_amount: 0,
    additional_costs: 0,
    total_landed_costs: 0,
    payment_status: 'unpaid',
    payment_status_label: 'Unpaid',
    payment_method: null,
    payment_method_label: null,
    payment_terms_days: null,
    payment_due_date: null,
    posted_by: null,
    posted_at: null,
    lines: [
      {
        id: 'grl-1',
        purchase_order_line_id: 'pol-1',
        purchase_material_line_id: null,
        supplier_invoice_line_id: null,
        product_id: 'prod-1',
        product: { id: 'prod-1', sku: 'SKU-1', name: 'Widget' },
        uom_id_snapshot: 'uom-1',
        uom_name_snapshot: 'Pieces',
        uom_symbol_snapshot: 'pcs',
        ordered_quantity: 100,
        gross_received_quantity: 100,
        net_received_quantity: 100,
        variance_quantity: 0,
        remaining_quantity: 0,
        unit_price: 10,
        landed_unit_cost: 11,
        weight_photo_path: null,
        weight_photo_url: null,
        notes: null,
      },
    ],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function renderForm() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SupplierReturnForm onCreated={vi.fn()} onCancel={vi.fn()} />
    </QueryClientProvider>,
  );
}

async function pickReceipt(receipt: GoodsReceipt) {
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: /receiptPlaceholder/i }));
  await user.click(await screen.findByRole('option', { name: new RegExp(receipt.receipt_number) }));
  return user;
}

describe('SupplierReturnForm', () => {
  beforeEach(() => {
    mockCreateMutate.mockReset();
    receiptList.mockReset();
    receiptGet.mockReset();
    suppliersList.mockReset();
  });

  it('disables submit until a Goods Receipt is chosen', () => {
    receiptList.mockResolvedValue({ items: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } });
    renderForm();

    expect(screen.getByRole('button', { name: 'submit' })).toBeDisabled();
  });

  it('derives the supplier from the receipt\'s Purchase Order and submits a valid line', async () => {
    const receipt = baseReceipt();
    receiptList.mockResolvedValue({
      items: [receipt],
      meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    });
    receiptGet.mockResolvedValue(receipt);

    renderForm();
    const user = await pickReceipt(receipt);

    await waitFor(() => expect(screen.getByDisplayValue('Acme Supplies')).toBeInTheDocument());
    expect(screen.getByDisplayValue('Acme Supplies')).toBeDisabled();

    const qtyInput = screen.getByPlaceholderText('0');
    await user.type(qtyInput, '5');

    await user.click(screen.getByRole('button', { name: 'submit' }));

    await waitFor(() => expect(mockCreateMutate).toHaveBeenCalledTimes(1));
    const [payload] = mockCreateMutate.mock.calls[0];
    expect(payload.supplier_id).toBe('sup-1');
    expect(payload.warehouse_id).toBe('wh-1');
    expect(payload.goods_receipt_id).toBe('gr-1');
    expect(payload.lines).toEqual([
      expect.objectContaining({
        product_id: 'prod-1',
        goods_receipt_line_id: 'grl-1',
        return_quantity: 5,
        original_received_qty: 100,
      }),
    ]);
  });

  it('refuses a return quantity beyond what was received, without calling create', async () => {
    const receipt = baseReceipt();
    receiptList.mockResolvedValue({
      items: [receipt],
      meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    });
    receiptGet.mockResolvedValue(receipt);

    renderForm();
    const user = await pickReceipt(receipt);

    const qtyInput = await screen.findByPlaceholderText('0');
    await user.type(qtyInput, '999');
    await user.click(screen.getByRole('button', { name: 'submit' }));

    await waitFor(() => expect(screen.getByText(/qtyExceedsReceived/)).toBeInTheDocument());
    expect(mockCreateMutate).not.toHaveBeenCalled();
  });
});

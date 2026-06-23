import { z } from 'zod';

import type { GoodsReceipt, GoodsReceiptPayload } from '@/features/goods-receipts/types/goods-receipt';

export const grLineSchema = z.object({
  purchase_order_line_id: z.string().min(1, 'PO line is required.'),
  product_id: z.string().min(1, 'Product is required.'),
  ordered_quantity: z.number().positive(),
  received_quantity: z
    .string()
    .min(1, 'Received qty is required.')
    .refine((v) => Number(v) >= 0, 'Cannot be negative.'),
});

export const goodsReceiptSchema = z.object({
  purchase_order_id: z.string().min(1, 'Purchase order is required.'),
  warehouse_id: z.string().min(1, 'Warehouse is required.'),
  receipt_date: z.string().min(1, 'Receipt date is required.'),
  notes: z.string().max(2000).optional(),
  lines: z.array(grLineSchema).min(1, 'At least one line is required.'),
});

export type GrLineFormValues = z.infer<typeof grLineSchema>;
export type GoodsReceiptFormValues = z.infer<typeof goodsReceiptSchema>;

export function toFormValues(receipt?: GoodsReceipt | null): GoodsReceiptFormValues {
  return {
    purchase_order_id: receipt?.purchase_order_id ?? '',
    warehouse_id: receipt?.warehouse_id ?? '',
    receipt_date: receipt?.receipt_date ?? new Date().toISOString().slice(0, 10),
    notes: receipt?.notes ?? '',
    lines:
      receipt?.lines.map((l) => ({
        purchase_order_line_id: l.purchase_order_line_id,
        product_id: l.product_id,
        ordered_quantity: l.ordered_quantity,
        received_quantity: String(l.received_quantity),
      })) ?? [],
  };
}

export function toPayload(values: GoodsReceiptFormValues): GoodsReceiptPayload {
  return {
    purchase_order_id: values.purchase_order_id,
    warehouse_id: values.warehouse_id,
    receipt_date: values.receipt_date,
    notes: values.notes || null,
    lines: values.lines.map((l) => ({
      purchase_order_line_id: l.purchase_order_line_id,
      product_id: l.product_id,
      ordered_quantity: l.ordered_quantity,
      received_quantity: Number(l.received_quantity),
    })),
  };
}

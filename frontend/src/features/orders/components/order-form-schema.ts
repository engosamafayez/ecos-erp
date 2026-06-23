import { z } from 'zod';

import type { Order, OrderPayload, OrderStatus } from '@/features/orders/types/order';

const ORDER_STATUSES: [OrderStatus, ...OrderStatus[]] = [
  'pending',
  'processing',
  'completed',
  'cancelled',
];

export const orderLineSchema = z.object({
  product_id: z.string().min(1, 'Product is required.'),
  quantity: z
    .string()
    .min(1, 'Quantity is required.')
    .refine((v) => Number(v) > 0, 'Quantity must be greater than 0.'),
  unit_price: z
    .string()
    .min(1, 'Unit price is required.')
    .refine((v) => Number(v) >= 0, 'Unit price must be 0 or greater.'),
});

export const orderSchema = z.object({
  channel_id: z.string().optional(),
  customer_id: z.string().min(1, 'Customer is required.'),
  external_order_id: z.string().max(255).optional(),
  order_date: z.string().min(1, 'Order date is required.'),
  status: z.enum(ORDER_STATUSES),
  notes: z.string().max(2000).optional(),
  lines: z.array(orderLineSchema).min(1, 'At least one line item is required.'),
});

export type OrderLineFormValues = z.infer<typeof orderLineSchema>;
export type OrderFormValues = z.infer<typeof orderSchema>;

export function toFormValues(order?: Order | null): OrderFormValues {
  return {
    channel_id: order?.channel_id ?? '',
    customer_id: order?.customer_id ?? '',
    external_order_id: order?.external_order_id ?? '',
    order_date: order?.order_date ?? new Date().toISOString().slice(0, 10),
    status: order?.status ?? 'pending',
    notes: order?.notes ?? '',
    lines:
      order?.lines.map((l) => ({
        product_id: l.product_id,
        quantity: String(l.quantity),
        unit_price: String(l.unit_price),
      })) ?? [{ product_id: '', quantity: '', unit_price: '' }],
  };
}

export function toPayload(values: OrderFormValues): OrderPayload {
  return {
    channel_id: values.channel_id || null,
    customer_id: values.customer_id,
    external_order_id: values.external_order_id || null,
    order_date: values.order_date,
    status: values.status,
    notes: values.notes || null,
    lines: values.lines.map((l) => ({
      product_id: l.product_id,
      quantity: Number(l.quantity),
      unit_price: Number(l.unit_price),
    })),
  };
}

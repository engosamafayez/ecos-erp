import { z } from 'zod';

import type { ManualOrderPayload, Order, OrderPayload, OrderStatus } from '@/features/orders/types/order';

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

// ── Manual order schema (enterprise create + edit form) ───────────────────────

export const manualOrderLineSchema = z.object({
  product_id: z.string().min(1, 'Product is required.'),
  quantity: z
    .string()
    .min(1, 'Quantity is required.')
    .refine((v) => Number(v) > 0, 'Quantity must be greater than 0.'),
  unit_price: z.string(), // May be empty on first render — auto-filled by pricing hook
});

export const manualOrderSchema = z.object({
  company_id:               z.string().optional(),
  channel_id:               z.string().optional(),
  status:                   z.string().optional(),
  order_date:               z.string().optional(),
  requested_delivery_date:  z.string().optional(),
  delivery_window_id:       z.string().optional(),
  delivery_window:          z.string().optional(),
  customer_id:              z.string().optional(),
  customer_name:            z.string().optional(),
  customer_phone:           z.string().optional(),
  customer_secondary_phone: z.string().optional(),
  customer_notes:           z.string().optional(),
  governorate:              z.string().optional(),
  city:                     z.string().optional(),
  area:                     z.string().optional(),
  shipping_address:         z.string().optional(),
  delivery_zone_id:         z.string().optional(),
  delivery_zone:            z.string().optional(),
  google_maps_lat:          z.number().optional(),
  google_maps_lng:          z.number().optional(),
  payment_method_manual:    z.string().optional(),
  shipping_cost:            z.string().optional(),
  shipping_cost_source:     z.string().optional(),
  discount_type:            z.string().optional(),
  discount_amount:          z.string().optional(),
  deposit_amount:           z.string().optional(),
  payment_proof_path:       z.string().optional(),
  notes:                    z.string().optional(),
  lines: z.array(manualOrderLineSchema).min(1, 'At least one line item is required.'),
});

export type ManualOrderFormValues    = z.infer<typeof manualOrderSchema>;
export type ManualOrderLineFormValues = z.infer<typeof manualOrderLineSchema>;

export function toManualPayload(values: ManualOrderFormValues): ManualOrderPayload {
  return {
    company_id:               values.company_id || null,
    channel_id:               values.channel_id || null,
    status:                   values.status || 'in_progress',
    order_date:               values.order_date || null,
    requested_delivery_date:  values.requested_delivery_date || null,
    delivery_window_id:       values.delivery_window_id || null,
    delivery_window:          values.delivery_window || null,
    customer_id:              values.customer_id || null,
    customer_name:            values.customer_name || null,
    customer_phone:           values.customer_phone || null,
    customer_secondary_phone: values.customer_secondary_phone || null,
    customer_notes:           values.customer_notes || null,
    governorate:              values.governorate || null,
    city:                     values.city || null,
    area:                     values.area || null,
    shipping_address:         values.shipping_address || null,
    delivery_zone_id:         values.delivery_zone_id || null,
    delivery_zone:            values.delivery_zone || null,
    google_maps_lat:          values.google_maps_lat ?? null,
    google_maps_lng:          values.google_maps_lng ?? null,
    payment_method_manual:    values.payment_method_manual || null,
    shipping_cost:            values.shipping_cost ? Number(values.shipping_cost) : null,
    shipping_cost_source:     values.shipping_cost_source || null,
    discount_amount:          values.discount_amount ? Number(values.discount_amount) : null,
    discount_type:            values.discount_type || null,
    deposit_amount:           values.deposit_amount ? Number(values.deposit_amount) : null,
    payment_proof_path:       values.payment_proof_path || null,
    notes:                    values.notes || null,
    lines: values.lines
      .filter((l) => Boolean(l.product_id))
      .map((l) => ({
        product_id: l.product_id,
        quantity:   Number(l.quantity),
        unit_price: Number(l.unit_price),
      })),
  };
}

export function toEditPayload(values: ManualOrderFormValues): OrderPayload {
  return {
    channel_id:  values.channel_id || null,
    customer_id: values.customer_id ?? '',
    order_date:  values.order_date ?? new Date().toISOString().slice(0, 10),
    status:      (values.status ?? 'pending') as OrderStatus,
    notes:       values.notes || null,
    lines: values.lines
      .filter((l) => Boolean(l.product_id))
      .map((l) => ({
        product_id: l.product_id,
        quantity:   Number(l.quantity),
        unit_price: Number(l.unit_price),
      })),
  };
}

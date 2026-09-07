import type { TFunction } from 'i18next';

import type { Order } from '@/features/orders/types/order';

// ── Copy / Print / Export share ONE field authority ────────────────────────────
// Defects A/B/C (user review) were all the same root cause: each action
// re-derived its own ad hoc, incomplete field list instead of reading from the
// Order objects the grid already fetched (which already carry everything
// OrderResource exposes). Centralizing the field set here means Copy, Print
// and Export can never drift out of sync with each other the way the three
// previous inline implementations had.

export type OrderFieldKey =
  | 'order_number'
  | 'customer'
  | 'phone'
  | 'status'
  | 'channel'
  | 'brand'
  | 'warehouse'
  | 'delivery_date'
  | 'address'
  | 'zone'
  | 'gps'
  | 'items'
  | 'reservation_summary'
  | 'payment_method'
  | 'payment_status'
  | 'payment_proof_status'
  | 'products_total'
  | 'shipping'
  | 'grand_total'
  | 'remaining_balance'
  | 'customer_notes'
  | 'created_at';

function formatAddress(o: Order): string {
  return [
    o.building && `Bldg ${o.building}`,
    o.floor && `Fl ${o.floor}`,
    o.apartment && `Apt ${o.apartment}`,
    o.shipping_address,
    o.area,
    o.city,
    o.governorate,
  ].filter(Boolean).join(', ');
}

function formatItems(o: Order): string {
  return o.lines.map((l) => `${l.product?.name ?? l.product_id} ×${l.quantity}`).join('; ');
}

function formatDeliveryDate(o: Order): string {
  if (!o.requested_delivery_date) return '';
  return o.preferred_delivery_time
    ? `${o.requested_delivery_date} ${o.preferred_delivery_time}`
    : o.requested_delivery_date;
}

function formatGps(o: Order): string {
  if (o.location?.lat != null && o.location?.lng != null) {
    return `${o.location.lat},${o.location.lng}`;
  }
  return o.google_maps_url ?? '';
}

// Inventory execution / reservation summary — mirrors the grid's own
// `inventory_execution` column authority (reservation_status +
// reservation_failure_reason), never a re-derived guess.
function formatReservationSummary(o: Order): string {
  if (!o.reservation_status) return '';
  return o.reservation_failure_reason
    ? `${o.reservation_status} (${o.reservation_failure_reason})`
    : o.reservation_status;
}

// Payment proof status — the canonical payment_proof_state/required pair
// (PaymentFulfillmentGate-backed), never the legacy payment_proof_path.
function formatPaymentProofStatus(o: Order): string {
  if (!o.payment_proof_required) return '';
  return o.payment_proof_state ?? 'none';
}

export const ORDER_FIELD_GETTERS: Record<OrderFieldKey, (o: Order) => string> = {
  order_number: (o) => o.order_number,
  customer: (o) => o.customer?.name ?? '',
  phone: (o) => o.billing_phone ?? o.customer?.phone ?? '',
  status: (o) => o.status_label ?? o.status,
  channel: (o) => o.channel?.name ?? '',
  brand: (o) => o.channel?.brand?.name ?? '',
  warehouse: (o) => o.assigned_warehouse?.name ?? '',
  delivery_date: formatDeliveryDate,
  address: formatAddress,
  zone: (o) => o.delivery_zone ?? '',
  gps: formatGps,
  items: formatItems,
  reservation_summary: formatReservationSummary,
  payment_method: (o) => o.payment_method_manual ?? o.payment_method ?? '',
  payment_status: (o) => o.payment_state ?? '',
  payment_proof_status: formatPaymentProofStatus,
  products_total: (o) => String(o.products_total ?? ''),
  shipping: (o) => String(o.shipping_amount ?? o.shipping_cost ?? ''),
  grand_total: (o) => String(o.grand_total ?? o.total ?? ''),
  remaining_balance: (o) => String(o.remaining_balance ?? ''),
  customer_notes: (o) => o.customer_note ?? '',
  created_at: (o) => o.created_at ?? '',
};

/** §4 minimum: Order Number, Customer, Phone, Status, Delivery Date, Address/Zone, Items, Payment method/status, Total. */
export const COPY_FIELD_KEYS: OrderFieldKey[] = [
  'order_number', 'customer', 'phone', 'status', 'delivery_date',
  'address', 'zone', 'items', 'payment_method', 'payment_status', 'grand_total',
];

/** §5 minimum: adds Channel, Warehouse, GPS, Inventory summary, Payment proof, Customer Notes. */
export const PRINT_FIELD_KEYS: OrderFieldKey[] = [
  'order_number', 'customer', 'phone', 'status', 'channel', 'warehouse',
  'delivery_date', 'address', 'zone', 'gps', 'items', 'reservation_summary',
  'payment_method', 'payment_status', 'payment_proof_status', 'grand_total', 'customer_notes',
];

/** §6 minimum: full superset, adds Brand, Products Total, Shipping, Remaining Balance, Created date. */
export const EXPORT_FIELD_KEYS: OrderFieldKey[] = [
  'order_number', 'customer', 'phone', 'status', 'channel', 'brand', 'warehouse',
  'delivery_date', 'address', 'zone', 'gps', 'items', 'reservation_summary',
  'payment_method', 'payment_status', 'payment_proof_status', 'products_total',
  'shipping', 'grand_total', 'remaining_balance', 'customer_notes', 'created_at',
];

/** Resolves a field's i18n label — reuses the grid's own column labels wherever one already exists. */
export function orderFieldLabel(key: OrderFieldKey, t: TFunction<'orders'>): string {
  switch (key) {
    case 'order_number':          return t($ => $.columns.number);
    case 'customer':               return t($ => $.columns.customer);
    case 'phone':                  return t($ => $.columns.phone);
    case 'status':                 return t($ => $.columns.status);
    case 'channel':                return t($ => $.columns.channel);
    case 'brand':                  return t($ => $.exportFields.brand);
    case 'warehouse':              return t($ => $.exportFields.warehouse);
    case 'delivery_date':          return t($ => $.columns.deliveryWindow);
    case 'address':                return t($ => $.columns.address);
    case 'zone':                   return t($ => $.columns.zone);
    case 'gps':                    return t($ => $.columns.location);
    case 'items':                  return t($ => $.columns.productsCount);
    case 'reservation_summary':    return t($ => $.columns.inventoryExecution);
    case 'payment_method':         return t($ => $.columns.paymentMethod);
    case 'payment_status':         return t($ => $.exportFields.paymentStatus);
    case 'payment_proof_status':   return t($ => $.columns.paymentProof);
    case 'products_total':         return t($ => $.exportFields.productsTotal);
    case 'shipping':                return t($ => $.exportFields.shipping);
    case 'grand_total':            return t($ => $.columns.total);
    case 'remaining_balance':      return t($ => $.exportFields.remainingBalance);
    case 'customer_notes':         return t($ => $.columns.customerNote);
    case 'created_at':             return t($ => $.columns.createdAt);
    default:                       return key;
  }
}

export function buildOrderRows(orders: Order[], keys: OrderFieldKey[]): string[][] {
  return orders.map((o) => keys.map((k) => ORDER_FIELD_GETTERS[k](o)));
}

function escapeCsvCell(v: string): string {
  return `"${v.replace(/"/g, '""')}"`;
}

export function rowsToCsv(headers: string[], rows: string[][]): string {
  return [headers, ...rows].map((r) => r.map(escapeCsvCell).join(',')).join('\n');
}

// TSV for clipboard: pastes as real columns into Excel/Sheets (unlike quoted
// CSV, which pastes as one text blob), while still reading fine as plain text.
function escapeTsvCell(v: string): string {
  return v.replace(/\t/g, ' ').replace(/\r?\n/g, ' ');
}

export function rowsToTsv(headers: string[], rows: string[][]): string {
  return [headers, ...rows].map((r) => r.map(escapeTsvCell).join('\t')).join('\n');
}

/**
 * TASK-ECOS-V1.1-CRM-04-CUSTOMER-SELF-SERVICE-UX-AND-FINAL-CLOSURE-020.
 *
 * These types mirror EXACTLY what the CRM-04 SelfService backend (Modules\Crm\SelfService)
 * returns — not the generic `ApiResponse<T>` envelope used elsewhere in this app. Every
 * `/api/track/*` controller in that module returns its own bespoke `{ data: ... }` /
 * `{ message, reason? }` shape (see CustomerOrderController, CustomerInvoiceController,
 * CustomerSupportController, CustomerPaymentMethodController, CustomerTrackingController).
 */

export type TrackBrand = {
  id: string;
  name: string;
  code: string;
} | null;

export type TrackOrderItem = {
  product_name: string | null;
  sku: string | null;
  quantity: number;
  unit_price: number;
  line_total: number;
};

export type TrackOrderDriver = { first_name: string } | null;

export type TrackOrderDelivery = {
  shipping_company: string | null;
  stop_status: string | null;
  stop_status_label: string | null;
  driver: TrackOrderDriver;
};

export type TrackTimelineEvent = {
  event: string;
  occurred_at: string;
};

export type TrackPostDeliveryWindow = {
  available: boolean;
  reason: string;
};

export type TrackSupportAvailability = {
  general_available: boolean;
  post_delivery_window: TrackPostDeliveryWindow;
};

/** The full CustomerOrderReadModel::build() payload — GET /track/order. */
export type TrackOrder = {
  order_number: string;
  order_date: string | null;
  brand: TrackBrand;
  requested_delivery_date: string | null;
  items: TrackOrderItem[];
  subtotal: number;
  shipping_amount: number;
  discount_amount: number;
  tax_amount: number;
  grand_total: number;
  paid_amount: number;
  outstanding_amount: number;
  payment_state: 'unpaid' | 'partially_paid' | 'paid';
  payment_proof_state: 'uploaded' | 'verified' | 'rejected' | null;
  canonical_status: string;
  canonical_status_label: string;
  delivery: TrackOrderDelivery;
  timeline: TrackTimelineEvent[];
  invoice_available: boolean;
  support: TrackSupportAvailability;
  payment_method_change_eligible: boolean;
};

export type TrackInvoiceLine = {
  description: string | null;
  quantity: number;
  unit_price: number;
  net_amount: number;
  tax_amount: number;
};

/** GET /track/order/invoice. */
export type TrackInvoice = {
  id: string;
  document_type: string;
  number: string;
  invoice_date: string | null;
  due_date: string | null;
  currency: string;
  subtotal: number;
  tax_total: number;
  total: number;
  status: string;
  outstanding: number | null;
  lines: TrackInvoiceLine[];
};

export type SupportCategory =
  | 'wrong_item'
  | 'damaged_item'
  | 'missing_item'
  | 'delivery_complaint'
  | 'payment_issue'
  | 'invoice_issue'
  | 'return_request'
  | 'general_support';

/** POST_DELIVERY categories the backend gates by the 30-day window (never general/payment/invoice). */
export const POST_DELIVERY_CATEGORIES: SupportCategory[] = [
  'wrong_item',
  'damaged_item',
  'missing_item',
  'return_request',
];

export type TrackTicketNote = {
  body: string;
  created_at: string | null;
};

export type TrackTicket = {
  ticket_number: string;
  type: string;
  subject: string;
  status: string;
  created_at: string | null;
  resolved_at: string | null;
  notes: TrackTicketNote[] | null;
};

export type TrackPaymentMethodOptions = {
  methods: string[];
};

/** Backend's generic `{ message }` / `{ message, reason }` error shape. */
export type TrackApiError = {
  message?: string;
  reason?: string;
  errors?: Record<string, string[]>;
};

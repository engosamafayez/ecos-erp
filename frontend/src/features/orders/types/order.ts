// ── Status ────────────────────────────────────────────────────────────────────
export type OrderStatus =
  | 'pending'               // legacy / fallback
  | 'processing'
  | 'waiting_for_payment'
  | 'review_confirmation'
  | 'review'
  | 'confirmed'
  | 'preparing'
  | 'shipping'
  | 'delivered'
  | 'delivery_delayed'
  | 'rejected'
  | 'waiting_for_stock'
  | 'postponed'
  | 'completed'
  | 'cancelled';

/** DD-011 — tab order must not be changed */
export const STATUS_TAB_ORDER: Array<OrderStatus | 'all'> = [
  'all',
  'processing',
  'waiting_for_payment',
  'review_confirmation',
  'review',
  'confirmed',
  'preparing',
  'shipping',
  'delivered',
  'delivery_delayed',
  'rejected',
  'waiting_for_stock',
  'postponed',
  'completed',
  'cancelled',
];

// ── Sub-types ─────────────────────────────────────────────────────────────────
export type OrderChannel  = { id: string; name: string };
export type OrderCustomer = { id: string; code: string; name: string };
export type OrderProduct  = { id: string; sku: string; name: string; image_url: string | null };
export type OrderFee      = { id: string; name: string; total: number };
export type OrderCoupon   = { id: string; code: string; discount: number };

export type OrderLine = {
  id: string;
  product_id: string;
  product: OrderProduct | null;
  quantity: number;
  unit_price: number;
  line_total: number;
};

export type OrderLocation = {
  lat: number;
  lng: number;
  label?: string | null;
  set_by?: 'customer' | 'employee' | null;
};

// ── Order ─────────────────────────────────────────────────────────────────────
export type Order = {
  id: string;
  channel_id: string | null;
  channel: OrderChannel | null;
  customer_id: string;
  customer: OrderCustomer | null;
  external_order_id: string | null;
  order_number: string;
  order_date: string;
  status: OrderStatus;
  status_label: string;
  subtotal: number;
  shipping_total: number;
  discount_total: number;
  tax_total: number;
  total: number;
  notes: string | null;
  customer_note: string | null;

  // Billing
  billing_first_name: string | null;
  billing_last_name: string | null;
  billing_company: string | null;
  billing_country: string | null;
  billing_state: string | null;
  billing_city: string | null;
  billing_address_1: string | null;
  billing_address_2: string | null;
  billing_postcode: string | null;
  billing_phone: string | null;
  billing_email: string | null;

  // Shipping address
  shipping_first_name: string | null;
  shipping_last_name: string | null;
  shipping_company: string | null;
  shipping_country: string | null;
  shipping_state: string | null;
  shipping_city: string | null;
  shipping_address_1: string | null;
  shipping_address_2: string | null;
  shipping_postcode: string | null;

  // Payment
  payment_method: string | null;
  payment_method_title: string | null;
  transaction_id: string | null;
  date_paid: string | null;

  // Shipping logistics
  shipping_method: string | null;
  shipping_company_name: string | null;  // carrier name (e.g. "DHL", "Aramex")
  shipping_attempts: number;             // 0 = never attempted
  tracking_number: string | null;

  // Location (single location, either from customer or set by employee)
  location: OrderLocation | null;

  // Line items
  fees: OrderFee[];
  coupons: OrderCoupon[];
  lines: OrderLine[];

  created_at: string | null;
  updated_at: string | null;
};

// ── Payloads ──────────────────────────────────────────────────────────────────
export type OrderLinePayload = {
  product_id: string;
  quantity: number;
  unit_price: number;
};

export type OrderPayload = {
  channel_id?: string | null;
  customer_id: string;
  external_order_id?: string | null;
  order_date: string;
  status: OrderStatus;
  notes?: string | null;
  lines: OrderLinePayload[];
};

// ── Query params ──────────────────────────────────────────────────────────────
export type OrderSortField = 'order_number' | 'order_date' | 'status' | 'total' | 'created_at';
export type SortDirection  = 'asc' | 'desc';

// DD-025 — Customer Intelligence filter options
export type CustomerIntelligenceFilter =
  | 'first_order'
  | 'repeated'
  | 'more_than_5'
  | 'more_than_10'
  | 'has_cancelled'
  | 'has_rejected'
  | 'incomplete';

export type OrdersQuery = {
  search?: string;
  status?: OrderStatus | 'all';
  channel_id?: string;        // DD-023
  product_id?: string;        // DD-024
  payment_method?: string;    // DD-026
  shipping_company?: string;  // DD-026
  date_from?: string;         // DD-026
  date_to?: string;           // DD-026
  city?: string;              // DD-028 Same Governorate
  has_location?: boolean;     // DD-028 Orders without Location
  min_shipping_attempts?: number; // DD-028 Multiple Attempts
  min_products?: number;      // DD-028 3+ Products
  customer_filter?: CustomerIntelligenceFilter; // DD-025
  customer_id?: string;
  page?: number;
  per_page?: number;
  sort_by?: OrderSortField;
  sort_dir?: SortDirection;
};

// ── Response types ────────────────────────────────────────────────────────────
export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type OrdersResult = {
  items: Order[];
  meta: PaginationMeta;
};

// ── Status counts (for tabs) ──────────────────────────────────────────────────
export type OrderStatusCounts = Partial<Record<OrderStatus | 'all', number>>;

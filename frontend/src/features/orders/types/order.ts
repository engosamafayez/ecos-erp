// ── Status ────────────────────────────────────────────────────────────────────
// Active backend values (OrderStatus PHP enum)
// Legacy values kept for backward compat with older records still in the DB.
export type OrderStatus =
  | 'pending'
  | 'in_progress'
  | 'processing'
  | 'preparing'
  | 'ready_for_loading'
  | 'awaiting_payment'
  | 'confirm_order'
  | 'out_for_delivery'
  | 'returned'
  | 'completed'
  | 'cancelled'
  // ── legacy (pre-refactor records) ──────────────────────────────────────────
  | 'waiting_for_payment'
  | 'review_confirmation'
  | 'review'
  | 'confirmed'
  | 'shipping'
  | 'delivered'
  | 'delivery_delayed'
  | 'rejected'
  | 'waiting_for_stock'
  | 'postponed';

/** DD-011 — tab order must not be changed */
export const STATUS_TAB_ORDER: Array<OrderStatus | 'all'> = [
  'all',
  'pending',
  'in_progress',
  'processing',
  'awaiting_payment',
  'confirm_order',
  'preparing',
  'ready_for_loading',
  'out_for_delivery',
  'returned',
  'completed',
  'cancelled',
];

// ── Sub-types ─────────────────────────────────────────────────────────────────
export type OrderChannel  = { id: string; name: string };
export type OrderCustomer = { id: string; code: string; name: string };
export type OrderProduct  = { id: string; sku: string; name: string; image_url: string | null; unit_name?: string | null };
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

// ── Shipping pricing rules ────────────────────────────────────────────────────
export type ShippingPricingRule = {
  id: string;
  company_id: string | null;
  governorate: string;
  city: string | null;
  area: string | null;
  standard_cost: number;
  express_cost: number | null;
  is_active: boolean;
};

export type ShippingCalcResult = {
  found: boolean;
  standard_cost: number | null;
  matched_level: string | null;
};

// ── Product pricing (approved price for manual orders) ────────────────────────
export type ProductPricingResult = {
  product_id: string;
  approved_price: number | null;
  has_pending_review: boolean;
};

// ── Financial snapshot ────────────────────────────────────────────────────────
export type MarginStatus = 'within_target' | 'above_target' | 'below_target';

export type OrderBusinessContextSnapshot = {
  captured_at: string | null;
  brand_context: { name: string | null };
  channel_context: { name: string | null; type: string | null };
  decision_provenance: {
    price: { source: string | null };
    cost: { source: string | null; recipe_version: string | null };
    discount: { source: string | null; manual_override: boolean };
    shipping: { zone: string | null };
  };
  customer_context: {
    delivery_success_rate: number | null;
    tier: string | null;
    segment: string | null;
  };
  policy_versions: { pricing: string | null; shipping: string | null };
  marketing_context: {
    campaign_name: string | null;
    utm_source: string | null;
    utm_medium: string | null;
  };
};

export type OrderFinancialSnapshotLine = {
  id: string;
  product_name: string | null;
  product_sku: string | null;
  quantity: number;
  unit_price_at_sale: number;
  regular_price_at_sale: number | null;
  sale_price_at_sale: number | null;
  line_total: number;
  unit_cost: number | null;
  line_cost: number | null;
  raw_material_cost: number | null;
  packaging_cost: number | null;
  manufacturing_cost: number | null;
  other_cost: number | null;
  gross_profit: number | null;
  margin_percent: number | null;
  margin_status: MarginStatus | null;
  target_margin_percent: number | null;
  source_recipe_version: string | null;
  bom_version_number: number | null;
  price_review_id: string | null;
  price_review_approved_at: string | null;
};

export type OrderFinancialSnapshot = {
  id: string;
  snapshot_uuid: string;
  snapshot_version: number;
  snapshotted_at: string;
  locked: boolean;
  locked_at: string | null;
  hash_verified: boolean | null;
  integrity_hash: string | null;
  currency: string;
  recipe_version: string | null;
  pricing_engine_version: string;
  cost_engine_version: string;
  subtotal: number;
  discount_amount: number;
  discount_type: string | null;
  shipping_cost: number;
  grand_total: number;
  deposit_amount: number;
  remaining_balance: number;
  total_cogs: number | null;
  total_raw_material_cost: number | null;
  total_packaging_cost: number | null;
  total_manufacturing_cost: number | null;
  total_other_cost: number | null;
  gross_profit: number | null;
  actual_margin_percent: number | null;
  margin_status: MarginStatus | null;
  target_margin_percent: number | null;
  margin_difference: number | null;
  shipping_zone: string | null;
  shipping_rule_name: string | null;
  shipping_override_applied: boolean;
  business_context: OrderBusinessContextSnapshot | null;
  lines: OrderFinancialSnapshotLine[];
};

// ── Customer lookup (manual order phone-first resolution) ─────────────────────
export type CustomerLookupStats = {
  total_orders: number;
  delivered: number;
  cancelled: number;
  success_rate: number;
  lifetime_value: number;
  last_order_date: string | null;
};

export type CustomerAddress = {
  id: string;
  is_default: boolean;
  city: string | null;
  area: string | null;
  address_line: string | null;
  google_maps_lat: number | null;
  google_maps_lng: number | null;
};

export type CustomerLookupCustomer = {
  id: string;
  name: string;
  phone: string | null;
};

export type CustomerLookupResult = {
  customer: CustomerLookupCustomer;
  addresses: CustomerAddress[];
  stats: CustomerLookupStats;
} | null;

// ── Manual order payload for POST /orders/manual ──────────────────────────────
export type ManualOrderPayload = {
  company_id?: string | null;
  channel_id?: string | null;
  status?: string;
  order_date?: string | null;
  requested_delivery_date?: string | null;
  delivery_window_id?: string | null;
  delivery_window?: string | null;
  customer_id?: string | null;
  customer_name?: string | null;
  customer_phone?: string | null;
  customer_secondary_phone?: string | null;
  customer_notes?: string | null;
  governorate?: string | null;
  city?: string | null;
  area?: string | null;
  shipping_address?: string | null;
  delivery_zone_id?: string | null;
  delivery_zone?: string | null;
  google_maps_lat?: number | null;
  google_maps_lng?: number | null;
  payment_method_manual?: string | null;
  shipping_cost?: number | null;
  shipping_cost_source?: string | null;
  discount_amount?: number | null;
  discount_type?: string | null;
  deposit_amount?: number | null;
  payment_proof_path?: string | null;
  notes?: string | null;
  lines: { product_id: string; quantity: number; unit_price: number }[];
};

export type CustomerBrand = {
  id: string;
  brand_id: string;
  brand_name: string | null;
  brand_code: string | null;
  is_primary: boolean;
  status: string;
  orders_count: number;
  lifetime_value: string;
  first_order_at: string | null;
  last_order_at: string | null;
  created_at: string | null;
};

export type CustomerTopProduct = {
  product_id: string | null;
  product_name: string | null;
  total_quantity: number;
  /** Distinct qualifying orders containing this product — the Product Affinity ranking key. */
  orders_count: number;
};

/**
 * A Customer becomes a "Repeat Customer" at this many qualifying orders, and it is the
 * default minimum for the product-specific repeat-buyer filter — "repeat" means the same
 * thing everywhere it appears. Mirrors CustomerOrderMetricsService::REPEAT_ORDER_THRESHOLD.
 */
export const REPEAT_ORDER_THRESHOLD = 2;

export type CustomerPurchasedProduct = {
  product_id: string | null;
  product_name: string | null;
  product_sku: string | null;
  total_quantity: number;
  orders_count: number;
  last_ordered_at: string | null;
};

export type CustomerChannel = {
  channel_id: string;
  channel_name: string | null;
  orders_count: number;
};

export type Customer = {
  id: string;
  company_id: string | null;
  /** Nullable until a future task adds the assignment action — every customer is unassigned today. */
  sales_owner_id: string | null;
  sales_owner_name: string | null;
  code: string;
  name: string;
  contact_person: string | null;
  email: string | null;
  phone: string | null;
  mobile: string | null;
  country: string | null;
  city: string | null;
  address: string | null;
  notes: string | null;
  is_active: boolean;
  brands: CustomerBrand[];

  // ── Order-derived facts ────────────────────────────────────────────────────
  // All computed server-side by CustomerOrderMetricsService — the SAME service and the
  // SAME definitions the CRM workspace uses. Never recomputed in the client.
  orders_count: number;
  total_order_value: number;
  delivered_count: number;
  /** delivered / ALL orders × 100. NULL when the customer has never ordered. */
  receiving_rate: number | null;
  average_order_value: number | null;
  last_order_at: string | null;
  /** NULL until the first qualifying order (no orders yet). */
  first_order_at: string | null;
  /** orders_count >= REPEAT_ORDER_THRESHOLD. Computed server-side, single source of truth. */
  is_repeat_customer: boolean;
  /** Average days between qualifying orders. NULL when fewer than 2 orders — never a
   *  divide-by-zero; 0 is a real, valid value (same-day repeat orders). */
  avg_days_between_orders: number | null;
  /** Number of DISTINCT products ordered, not units. */
  top_products_count: number;
  top_products: CustomerTopProduct[];
  /** Canonical `orders.google_maps_url` from the most recent order carrying one. */
  location_url: string | null;
  full_address: string | null;
  /** Most frequent orders.governorate, computed server-side. NULL when no order carries one. */
  preferred_governorate: string | null;
  /** Distinct Channels ordered through, most-used first. Derived from order history, not maintained. */
  channels: CustomerChannel[];
  /** Returned by GET /customers/{id} only — the list omits it by design (heavier query). */
  purchased_products?: CustomerPurchasedProduct[];
  created_at: string | null;
  updated_at: string | null;
};

export type CustomerPayload = {
  brand_id: string;
  /** Omitted (or blank) on create — the backend generates one. Required on update. */
  code?: string;
  name: string;
  contact_person?: string;
  email?: string;
  phone?: string;
  mobile?: string;
  country?: string;
  city?: string;
  address?: string;
  notes?: string;
  is_active: boolean;
};

export type CustomerStatusFilter = 'all' | 'active' | 'inactive';
export type CustomerSortField =
  | 'code'
  | 'name'
  | 'country'
  | 'city'
  | 'is_active'
  | 'created_at'
  | 'total_order_value'
  | 'orders_count'
  | 'last_order_at';
export type SortDirection = 'asc' | 'desc';

export type CustomersQuery = {
  search?: string;
  status?: CustomerStatusFilter;
  brand_id?: string;
  country?: string;
  city?: string;
  /** Repeat Customers only (orders_count >= REPEAT_ORDER_THRESHOLD). Backend-authoritative. */
  repeat_only?: boolean;
  /** Product Affinity filter: customers who purchased this product repeatedly. */
  product_id?: string;
  /** Defaults to REPEAT_ORDER_THRESHOLD server-side when product_id is set. */
  min_purchase_count?: number;
  page?: number;
  per_page?: number;
  sort_by?: CustomerSortField;
  sort_dir?: SortDirection;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type CustomersResult = {
  items: Customer[];
  meta: PaginationMeta;
};

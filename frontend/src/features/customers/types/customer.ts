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

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 * One block EPISODE — a row with `unblocked_at` set is a closed/historical
 * episode, not the current state. See BlockedCustomerPolicy on the backend.
 */
export type CustomerBlock = {
  id: string;
  company_id: string;
  customer_id: string | null;
  normalized_phone: string;
  is_active: boolean;
  block_reason: string;
  blocked_by: string | null;
  /** Canonical human-readable actor identity — "System" when blocked_by is null. Optional
   *  (rather than required) so it doesn't force every existing test fixture/mock literal
   *  of this type to be updated — the real API always sends it. */
  blocked_by_name?: string | null;
  blocked_at: string;
  unblock_reason: string | null;
  unblocked_by: string | null;
  /** Null when this episode was never unblocked, not just an unresolved name. Optional
   *  for the same fixture-compatibility reason as blocked_by_name above. */
  unblocked_by_name?: string | null;
  unblocked_at: string | null;
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

  // ── Blocked Customer (TASK-...-BLOCKED-CUSTOMERS-009) ──────────────────────
  /** Current state only — see block-history for the full timeline. */
  is_blocked: boolean;
  block_reason: string | null;
  blocked_at: string | null;
  blocked_by: string | null;
  /** Canonical human-readable actor identity — "System" when blocked_by is null,
   *  null when the customer isn't currently blocked at all. Optional (rather than
   *  required) so it doesn't force every existing test fixture/mock Customer literal
   *  to be updated — the real API always sends it. */
  blocked_by_name?: string | null;
  /** The ACTIVE customer_blocks id — required by POST .../unblock as `block_id`. */
  customer_block_id: string | null;
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

/** TASK-...-FINAL-UI-CLOSURE-014 (§18) — Order Activity classification. 'repeat' reuses
 *  the same REPEAT_ORDER_THRESHOLD definition repeat_only already uses. */
export type OrderActivityFilter = 'no_orders' | 'one_time' | 'repeat';

export type CustomersQuery = {
  search?: string;
  status?: CustomerStatusFilter;
  brand_id?: string;
  country?: string;
  city?: string;
  /** TASK-...-FINAL-UI-CLOSURE-014 (§15) — a specific Sales Owner id. */
  sales_owner_id?: string;
  /** Customers with no Sales Owner assigned at all — an honest NULL check. */
  unassigned_sales_owner?: boolean;
  /** TASK-...-FINAL-UI-CLOSURE-014 (§16) — Channel filter (derived: Channel is order-level). */
  channel_id?: string;
  /** TASK-...-FINAL-UI-CLOSURE-014-R1 (§2) — the CTO-approved Top Spenders population
   *  segment (top 20% of eligible Customers by total_order_value, tenant-wide).
   *  Deliberately separate from sort_by='total_order_value' ("Highest Spend" — a
   *  sort, not a segment). Backend-authoritative. */
  top_spenders?: boolean;
  /** Repeat Customers only (orders_count >= REPEAT_ORDER_THRESHOLD). Backend-authoritative. */
  repeat_only?: boolean;
  /** Product Affinity filter: customers who purchased this product repeatedly. */
  product_id?: string;
  /** Defaults to REPEAT_ORDER_THRESHOLD server-side when product_id is set. */
  min_purchase_count?: number;
  order_activity?: OrderActivityFilter;
  /** Blocked Customers filter/segment. Backend-authoritative. */
  blocked_only?: boolean;
  /** TASK-...-FINAL-UI-CLOSURE-014 (§17) — the honest inverse of blocked_only. */
  not_blocked_only?: boolean;
  page?: number;
  per_page?: number;
  sort_by?: CustomerSortField;
  sort_dir?: SortDirection;
};

/** GET /customers/sales-owners — distinct Sales Owners currently referenced by this
 *  company's Customers, read off the existing denormalised column. Not an employee
 *  directory: naturally empty until a future task adds the assignment action. */
export type SalesOwnerOption = {
  id: string;
  name: string | null;
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

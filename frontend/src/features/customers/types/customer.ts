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
};

export type CustomerPurchasedProduct = {
  product_id: string | null;
  product_name: string | null;
  product_sku: string | null;
  total_quantity: number;
  orders_count: number;
  last_ordered_at: string | null;
};

export type Customer = {
  id: string;
  company_id: string | null;
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
  /** Number of DISTINCT products ordered, not units. */
  top_products_count: number;
  top_products: CustomerTopProduct[];
  /** Canonical `orders.google_maps_url` from the most recent order carrying one. */
  location_url: string | null;
  full_address: string | null;
  /** Most frequent orders.governorate, computed server-side. NULL when no order carries one. */
  preferred_governorate: string | null;
  /** Returned by GET /customers/{id} only — the list omits it by design (heavier query). */
  purchased_products?: CustomerPurchasedProduct[];
  created_at: string | null;
  updated_at: string | null;
};

export type CustomerPayload = {
  brand_id: string;
  code: string;
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
export type CustomerSortField = 'code' | 'name' | 'country' | 'city' | 'is_active' | 'created_at';
export type SortDirection = 'asc' | 'desc';

export type CustomersQuery = {
  search?: string;
  status?: CustomerStatusFilter;
  brand_id?: string;
  country?: string;
  city?: string;
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

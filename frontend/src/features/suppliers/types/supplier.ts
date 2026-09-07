/**
 * Suppliers feature types.
 */
export type SupplierCategory = {
  id: string;
  code: string;
  name: string;
  name_ar: string | null;
  is_active: boolean;
  created_at: string | null;
};

export type SupplierCategoryPayload = {
  // Server-generated on create (§3) — only present when updating an existing category, to
  // carry its own unchanged code back.
  code?: string;
  name: string;
  name_ar?: string;
  is_active: boolean;
};

export type SupplierRawMaterial = {
  id: string;
  sku: string;
  name: string;
};

export type SupplierProductCategoryCapability = {
  id: string;
  code: string;
  name: string;
};

export type Supplier = {
  id: string;
  code: string;
  supplier_category_id: string | null;
  supplier_category_name?: string | null;
  name: string;
  contact_person: string | null;
  email: string | null;
  phone: string | null;
  mobile: string | null;
  // Location (Part 1)
  country: string | null;
  state: string | null;
  city: string | null;
  district: string | null;
  address: string | null;
  google_maps_url: string | null;
  // Opening balance (Part 2) + Previous balance (Part 3)
  opening_balance_amount?: number;
  opening_balance_type?: 'debit' | 'credit';
  opening_balance?: number;
  previous_balance?: number;
  notes: string | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
  // Aggregate fields — populated on list endpoint (LEFT JOIN subqueries), optional on single-record fetch
  total_invoiced?: number;
  total_paid?: number;
  outstanding_balance?: number;
  last_purchase_date?: string | null;
  active_pos_count?: number;
  inventory_cost_value?: number;
  // Grid financial columns (Part 6) — derived server-side from aggregates
  purchase_balance?: number;
  total_purchased_value?: number;
  total_outstanding?: number;
  current_supplier_balance?: number;
  // Supply Capabilities (TASK-...-SUPPLY-CAPABILITIES-003). Full sets on
  // single-record fetch (Supplier detail); counts only on the list endpoint.
  raw_materials?: SupplierRawMaterial[];
  product_categories?: SupplierProductCategoryCapability[];
  raw_material_count?: number;
  product_category_count?: number;
};

export type SupplierPayload = {
  // Backend-owned — omitted on create (auto-generated) and ignored on update
  // (TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002).
  code?: string;
  supplier_category_id?: string | null;
  raw_material_ids?: string[];
  product_category_ids?: string[];
  name: string;
  contact_person?: string;
  email?: string;
  phone?: string;
  mobile?: string;
  country?: string;
  state?: string;
  city?: string;
  district?: string;
  address?: string;
  google_maps_url?: string;
  opening_balance_amount?: number;
  opening_balance_type?: 'debit' | 'credit';
  notes?: string;
  is_active: boolean;
};

export type SupplierSortField = 'code' | 'name' | 'country' | 'city' | 'is_active' | 'created_at';
export type SortDirection = 'asc' | 'desc';
export type SupplierStatusFilter = 'all' | 'active' | 'inactive';

/** Six-state supplier lifecycle — maps to is_active for now; extended when backend adds multi-status. */
export type SupplierStatus = 'draft' | 'active' | 'preferred' | 'on_hold' | 'blocked' | 'archived';

/** Procurement health score tiers — computed server-side by GetProcurementHealthQuery. */
export type ProcurementHealth = 'excellent' | 'good' | 'watch' | 'risk' | 'critical';

export type SuppliersQuery = {
  search?: string;
  country?: string;
  city?: string;
  supplier_category_id?: string;
  raw_material_id?: string;
  product_category_id?: string;
  page?: number;
  per_page?: number;
  sort_by?: SupplierSortField;
  sort_dir?: SortDirection;
  status?: SupplierStatusFilter;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type SuppliersResult = {
  items: Supplier[];
  meta: PaginationMeta;
};

/**
 * Suppliers feature types.
 */
export type Supplier = {
  id: string;
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
  created_at: string | null;
  updated_at: string | null;
  // Aggregate fields — populated on list endpoint (LEFT JOIN subqueries), optional on single-record fetch
  total_invoiced?: number;
  total_paid?: number;
  outstanding_balance?: number;
  last_purchase_date?: string | null;
  active_pos_count?: number;
  inventory_cost_value?: number;
};

export type SupplierPayload = {
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

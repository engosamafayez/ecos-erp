/**
 * Products feature types.
 */
export type ProductType = 'finished_good' | 'raw_material';

export type ProductRef = {
  id: string;
  code: string;
  name: string;
  symbol?: string | null;
};

export type Product = {
  id: string;
  sku: string;
  barcode: string | null;
  name: string;
  description: string | null;
  category_id: string;
  category?: ProductRef;
  unit_id: string;
  unit?: ProductRef;
  product_type: ProductType;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type ProductPayload = {
  sku: string;
  barcode?: string;
  name: string;
  description?: string;
  category_id: string;
  unit_id: string;
  product_type: ProductType;
  is_active: boolean;
};

export type ProductSortField = 'sku' | 'name' | 'product_type' | 'is_active' | 'created_at';
export type SortDirection = 'asc' | 'desc';
export type ProductStatusFilter = 'all' | 'active' | 'inactive';

export type ProductsQuery = {
  search?: string;
  category_id?: string;
  unit_id?: string;
  product_type?: ProductType;
  page?: number;
  per_page?: number;
  sort_by?: ProductSortField;
  sort_dir?: SortDirection;
  status?: ProductStatusFilter;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type ProductsResult = {
  items: Product[];
  meta: PaginationMeta;
};

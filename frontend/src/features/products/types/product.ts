export type ProductType = 'finished_good' | 'raw_material';

export type ProductStockStatus = 'instock' | 'outofstock' | 'onbackorder';

export type SyncStatus = 'synced' | 'pending' | 'failed' | 'not_synced';

export type ProductRef = {
  id: string;
  code: string;
  name: string;
  symbol?: string | null;
};

/** Channel summary returned when a product is mapped to one or more channels. */
export type ProductChannel = {
  id: string;
  name: string;
  platform: string;
  is_synced: boolean;
  last_synced_at: string | null;
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
  image_url: string | null;
  regular_price: number | null;
  sale_price: number | null;
  material_cost?: number | null;
  last_purchase_cost?: number | null;
  short_description: string | null;
  long_description: string | null;
  stock_status: ProductStockStatus | null;
  created_at: string | null;
  updated_at: string | null;
  // --- Fields populated once the backend returns them ---
  /** Channels this product is mapped to. */
  channels?: ProductChannel[];
  /** Aggregate sync status across all mapped channels. */
  sync_status?: SyncStatus;
  /** True when the product is published on at least one channel. */
  is_published?: boolean;
  /** WooCommerce SKU (from channel mapping). */
  woo_sku?: string | null;
  /** Short display name. */
  short_name?: string | null;
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
  image_url?: string | null;
  regular_price?: number | null;
  sale_price?: number | null;
  short_description?: string | null;
  long_description?: string | null;
  stock_status?: ProductStockStatus | null;
};

export type ProductSortField =
  | 'sku'
  | 'name'
  | 'product_type'
  | 'is_active'
  | 'created_at'
  | 'updated_at'
  | 'regular_price';

export type SortDirection = 'asc' | 'desc';
export type ProductStatusFilter = 'all' | 'active' | 'inactive';

export type ProductsQuery = {
  search?: string;
  category_id?: string;
  unit_id?: string;
  warehouse_id?: string;
  channel_id?: string;
  product_type?: ProductType;
  page?: number;
  per_page?: number;
  sort_by?: ProductSortField;
  sort_dir?: SortDirection;
  status?: ProductStatusFilter;
  is_published?: boolean;
  low_stock?: boolean;
  out_of_stock?: boolean;
  has_images?: boolean;
  not_synced?: boolean;
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

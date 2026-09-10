export type ConnectionStatus = 'disconnected' | 'connected' | 'error';
export type ChannelHealthStatus = 'healthy' | 'warning' | 'error';

export type ChannelPlatform =
  | 'woocommerce'
  | 'shopify'
  | 'amazon'
  | 'noon'
  | 'salla'
  | 'zid';

export type Channel = {
  id: string;
  brand_id: string;
  brand: {
    id: string;
    code: string;
    name: string;
    company: { id: string; name: string } | null;
  } | null;
  business_account_id: string | null;
  business_account: { id: string; code: string; name: string; provider: string } | null;
  code: string | null;
  channel_type: string | null;
  channel_role: string | null;
  name: string;
  platform: ChannelPlatform;
  platform_label: string;
  store_url: string;
  is_active: boolean;
  sync_products: boolean;
  sync_prices: boolean;
  sync_stock: boolean;
  sync_customers: boolean;
  sync_orders: boolean;
  orders_sync_watermark_at: string | null;
  orders_initial_import_policy: string | null;
  orders_initial_import_cutoff_at: string | null;
  orders_sync_activated_at: string | null;
  connection_status: ConnectionStatus;
  connection_status_label: string;
  health_status: ChannelHealthStatus;
  last_sync_at: string | null;
  last_webhook_received_at: string | null;
  last_successful_sync_at: string | null;
  last_error_at: string | null;
  last_error_message: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type ChannelPayload = {
  brand_id: string;
  name: string;
  platform: ChannelPlatform;
  store_url: string;
  is_active: boolean;
  sync_products: boolean;
  sync_prices: boolean;
  sync_stock: boolean;
  sync_customers: boolean;
  // sync_orders is deliberately absent here — not settable via the generic channel update
  // payload (TASK-...-025 correction). See OrdersSyncDialog / setOrdersSyncState.
  consumer_key?: string;
  consumer_secret?: string;
};

export type ChannelStatusFilter = 'all' | 'active' | 'inactive';
export type ChannelSortField = 'name' | 'platform' | 'is_active' | 'last_sync_at' | 'created_at';
export type SortDirection = 'asc' | 'desc';

export type ChannelsQuery = {
  search?: string;
  status?: ChannelStatusFilter;
  platform?: string;
  company_id?: string;
  brand_id?: string;
  business_account_id?: string;
  page?: number;
  per_page?: number;
  sort_by?: ChannelSortField;
  sort_dir?: SortDirection;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type ChannelsResult = {
  items: Channel[];
  meta: PaginationMeta;
};

export type ImportResult = {
  imported: number;
  created_products: number;
  created_mappings: number;
  failed: number;
  categories_created: number;
  categories_updated: number;
  errors: string[];
};

export type OrderImportResult = {
  imported_orders: number;
  created_customers: number;
  created_orders: number;
  created_lines: number;
  skipped_orders: number;
  failed_lines: number;
  errors: string[];
};

export type ResetDomain = 'commerce' | 'operations' | 'inventory' | 'finance';

export type LifecycleState = 'pre_live' | 'live';

export type GoLiveStatus = {
  company_id: string;
  lifecycle_state: LifecycleState;
};

export type WooCutoverChannel = {
  channel_id: string;
  name: string;
  connection_status: string;
  health_status: string;
  sync_orders: boolean;
  orders_sync_watermark_at: string | null;
  orders_initial_import_policy: string | null;
  orders_sync_activated_at: string | null;
  last_successful_sync_at: string | null;
  last_error_at: string | null;
  last_error_message: string | null;
  sync_products: boolean;
  sync_prices: boolean;
  sync_stock: boolean;
  sync_customers: boolean;
};

export type ResetPreview = {
  company_id: string;
  selected_domains: ResetDomain[];
  counts: Record<string, Record<string, number>>;
  blockers: string[];
  is_safe: boolean;
  woo_cutover: WooCutoverChannel[];
  lifecycle_state: LifecycleState;
};

export type ResetPreviewPayload = {
  domains: ResetDomain[];
};

export type ResetExecutePayload = {
  domains: ResetDomain[];
  confirmation_phrase: string;
  idempotency_key: string;
  reason?: string;
};

export type ResetExecuteResult = {
  operation_id: string;
  status: 'executing' | 'completed' | 'failed';
  execution_counts: Record<string, Record<string, number>> | null;
  failure_stage: string | null;
  failure_message: string | null;
};

export type OpeningInventoryLine = {
  warehouse_id: string;
  product_id: string;
  quantity: number;
  unit_cost: number;
  notes?: string;
};

export type OpeningInventoryResult = {
  lines: Array<{ warehouse_id: string; product_id: string; quantity: number; status: string }>;
};

export type OpeningBalancePayload = {
  amount: number;
  opening_date: string;
  reference?: string;
  notes?: string;
};

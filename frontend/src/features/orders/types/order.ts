export type OrderStatus = 'pending' | 'processing' | 'completed' | 'cancelled';

export type OrderChannel = { id: string; name: string };
export type OrderCustomer = { id: string; code: string; name: string };
export type OrderProduct = { id: string; sku: string; name: string };

export type OrderLine = {
  id: string;
  product_id: string;
  product: OrderProduct | null;
  quantity: number;
  unit_price: number;
  line_total: number;
};

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
  total: number;
  notes: string | null;
  lines: OrderLine[];
  created_at: string | null;
  updated_at: string | null;
};

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

export type OrderSortField = 'order_number' | 'order_date' | 'status' | 'total' | 'created_at';
export type SortDirection = 'asc' | 'desc';

export type OrdersQuery = {
  search?: string;
  status?: OrderStatus | 'all';
  channel_id?: string;
  customer_id?: string;
  page?: number;
  per_page?: number;
  sort_by?: OrderSortField;
  sort_dir?: SortDirection;
};

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

/**
 * Purchase Orders feature types.
 */

export type PurchaseOrderStatus = 'draft' | 'approved' | 'cancelled';

export type PurchaseOrderLineProduct = {
  id: string;
  sku: string;
  name: string;
};

export type PurchaseOrderLine = {
  id: string;
  product_id: string;
  product: PurchaseOrderLineProduct | null;
  quantity: number;
  unit_price: number;
  line_total: number;
};

export type PurchaseOrderSupplier = {
  id: string;
  code: string;
  name: string;
};

export type PurchaseOrder = {
  id: string;
  po_number: string;
  supplier_id: string;
  supplier: PurchaseOrderSupplier | null;
  order_date: string;
  expected_date: string | null;
  status: PurchaseOrderStatus;
  notes: string | null;
  subtotal: number;
  total: number;
  lines: PurchaseOrderLine[];
  created_at: string | null;
  updated_at: string | null;
};

export type PurchaseOrderLinePayload = {
  product_id: string;
  quantity: number;
  unit_price: number;
};

export type PurchaseOrderPayload = {
  supplier_id: string;
  order_date: string;
  expected_date?: string | null;
  notes?: string | null;
  lines: PurchaseOrderLinePayload[];
};

export type PurchaseOrderSortField =
  | 'po_number'
  | 'order_date'
  | 'expected_date'
  | 'status'
  | 'total'
  | 'created_at';

export type SortDirection = 'asc' | 'desc';

export type PurchaseOrdersQuery = {
  search?: string;
  supplier_id?: string;
  status?: PurchaseOrderStatus | 'all';
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
  sort_by?: PurchaseOrderSortField;
  sort_dir?: SortDirection;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type PurchaseOrdersResult = {
  items: PurchaseOrder[];
  meta: PaginationMeta;
};

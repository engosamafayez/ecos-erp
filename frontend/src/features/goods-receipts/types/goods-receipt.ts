export type GoodsReceiptStatus = 'draft' | 'posted';

export type GoodsReceiptLineProduct = {
  id: string;
  sku: string;
  name: string;
};

export type GoodsReceiptLine = {
  id: string;
  purchase_order_line_id: string;
  product_id: string;
  product: GoodsReceiptLineProduct | null;
  ordered_quantity: number;
  received_quantity: number;
  remaining_quantity: number;
};

export type GoodsReceiptPO = {
  id: string;
  po_number: string;
};

export type GoodsReceiptWarehouse = {
  id: string;
  code: string;
  name: string;
};

export type GoodsReceipt = {
  id: string;
  receipt_number: string;
  purchase_order_id: string;
  purchase_order: GoodsReceiptPO | null;
  warehouse_id: string;
  warehouse: GoodsReceiptWarehouse | null;
  receipt_date: string;
  status: GoodsReceiptStatus;
  notes: string | null;
  lines: GoodsReceiptLine[];
  created_at: string | null;
  updated_at: string | null;
};

export type GoodsReceiptLinePayload = {
  purchase_order_line_id: string;
  product_id: string;
  ordered_quantity: number;
  received_quantity: number;
};

export type GoodsReceiptPayload = {
  purchase_order_id: string;
  warehouse_id: string;
  receipt_date: string;
  notes?: string | null;
  lines: GoodsReceiptLinePayload[];
};

export type GoodsReceiptSortField = 'receipt_number' | 'receipt_date' | 'status' | 'created_at';
export type SortDirection = 'asc' | 'desc';

export type GoodsReceiptsQuery = {
  search?: string;
  purchase_order_id?: string;
  warehouse_id?: string;
  status?: GoodsReceiptStatus | 'all';
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
  sort_by?: GoodsReceiptSortField;
  sort_dir?: SortDirection;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type GoodsReceiptsResult = {
  items: GoodsReceipt[];
  meta: PaginationMeta;
};

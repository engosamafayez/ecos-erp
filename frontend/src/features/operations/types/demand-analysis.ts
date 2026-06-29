export type InventoryStatus = 'READY' | 'SHORTAGE' | 'OUT_OF_STOCK' | 'UNKNOWN';

export type DemandLine = {
  product_id: string;
  sku: string;
  product_name: string;
  ordered_qty: number;
  reserved_qty: number;
  available_qty: number | null;
  required_qty: number;
  shortage_qty: number;
  affected_orders_count: number;
  affected_channels_count: number;
  warehouse_count: number;
  inventory_status: InventoryStatus;
};

export type DemandSummary = {
  total_orders: number;
  total_products: number;
  total_skus: number;
  ready_count: number;
  shortage_count: number;
  out_of_stock_count: number;
  unknown_count: number;
};

export type DemandAnalysisResult = {
  operational_day: string;
  generated_at: string;
  summary: DemandSummary;
  demand_lines: DemandLine[];
};

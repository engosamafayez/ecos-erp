export type SupplierAnalytics = {
  supplier_id: string;
  supplier_name: string;
  supplier_code: string;
  // Purchasing totals
  total_purchases: number;
  total_invoiced: number;
  total_paid: number;
  outstanding_balance: number;
  last_purchase_date: string | null;
  // Inventory
  current_inventory_quantity: number;
  current_inventory_cost_value: number;
  current_inventory_sale_value: number;
  potential_gross_profit: number;
};

export type SupplierInventoryProduct = {
  product_id: string;
  product_sku: string;
  product_name: string;
  average_cost: number | null;
  sale_price: number | null;
  remaining_quantity: number;
  cost_value: number;
  sale_value: number;
  gross_profit: number;
  oldest_receipt_date: string | null;
  latest_receipt_date: string | null;
  receipt_count: number;
};

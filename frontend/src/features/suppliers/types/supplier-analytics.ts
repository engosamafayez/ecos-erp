import type { ProcurementHealth } from './supplier';

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
  inventory_remaining_margin_percent: number;
  // Performance metrics
  avg_lead_time_days: number | null;
  on_time_delivery_rate: number | null;
  fill_rate: number | null;
  active_pos_count: number;
  pending_grs_count: number;
  total_products_supplied: number;
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

// ── Product Demand / Purchase Rate ────────────────────────────────────────────

/**
 * Product-level purchasing rate for one supplier — "how much of this product do
 * we normally need to buy from them again?".
 *
 * Distinct from SupplierPriceHistoryEntry (the chronological per-line purchase
 * log) and from SupplierInventoryProduct (what is still in stock). Every value
 * is a backend aggregate; nothing here is derived on the client.
 *
 * When `has_purchase_history` is false the pair exists (an open PO line, or a
 * supplier selected on a purchase material) but has never been received, and
 * every metric is null — it is NOT zero.
 */
export type SupplierProductDemand = {
  product_id: string;
  product_sku: string;
  product_name: string;
  unit_symbol: string | null;
  unit_name: string | null;
  has_purchase_history: boolean;
  /** Window the weekly/monthly averages are derived from. */
  average_basis_days: number;
  average_weekly_denominator_weeks: number;
  average_monthly_denominator_months: number;
  /** Most recent price actually paid to this supplier for this product. */
  supplier_price: number | null;
  last_purchase_date: string | null;
  last_purchase_quantity: number | null;
  first_purchase_date: string | null;
  total_quantity: number | null;
  purchase_line_count: number;
  quantity_7d: number | null;
  quantity_30d: number | null;
  quantity_90d: number | null;
  average_daily_quantity: number | null;
  average_weekly_quantity: number | null;
  average_monthly_quantity: number | null;
  price_trend: 'rising' | 'falling' | 'stable' | null;
  price_change_percent: number | null;
};

// ── Global KPI Stats ─────────────────────────────────────────────────────────

export type SupplierSummaryStats = {
  total_suppliers: number;
  active_suppliers: number;
  new_this_month: number;
  open_pos_total: number;
  delayed_pos: number;
  total_outstanding: number;
  total_inventory_value: number;
  needs_review_count: number;
};

// ── Procurement Health Score ──────────────────────────────────────────────────

// REALIGNMENT-001 §15 — a component is null when the supplier has no real data for it.
// The backend no longer substitutes a fabricated midpoint (50/75/100/30), so every consumer
// must render "No data" rather than a number it cannot justify.
export type ProcurementHealthComponents = {
  delivery_performance: number | null;
  fill_rate: number | null;
  price_stability: number | null;
  activity: number | null;
  financial_standing: number | null;
  inventory_impact: number | null;
};

export type ProcurementHealthResult = {
  supplier_id: string;
  /** False when the supplier has no procurement history at all — show an empty state. */
  has_history: boolean;
  /** Null when nothing can be scored honestly. */
  score: number | null;
  tier: ProcurementHealth | 'no_data';
  color: string;
  /** Null until a real prior-period series exists (was hard-coded 'stable'). */
  trend: 'up' | 'down' | 'stable' | null;
  components: ProcurementHealthComponents;
  weights: Record<string, number>;
};

// ── Price History ─────────────────────────────────────────────────────────────

export type SupplierPriceHistoryEntry = {
  id: string;
  date: string;
  po_number: string;
  warehouse_name: string;
  product_name: string;
  product_sku: string;
  quantity: number;
  unit_cost: number;
  landed_unit_cost: number | null;
  previous_price: number | null;
  price_diff_pct: number | null;
};

// ── Timeline ──────────────────────────────────────────────────────────────────

export type SupplierTimelineEventType =
  | 'supplier_created'
  | 'supplier_updated'
  | 'po_created'
  | 'po_approved'
  | 'gr_posted'
  | 'price_change';

export type SupplierTimelineEvent = {
  id: string;
  type: SupplierTimelineEventType;
  title: string;
  description: string | null;
  reference: string | null;
  actor: string | null;
  occurred_at: string;
};

// ── Documents ─────────────────────────────────────────────────────────────────

export type SupplierDocumentType =
  | 'commercial_registration'
  | 'tax_card'
  | 'contract'
  | 'certificate'
  | 'attachment';

export type SupplierDocument = {
  id: string;
  supplier_id: string;
  document_type: SupplierDocumentType;
  name: string;
  mime_type: string;
  file_size: number;
  notes: string | null;
  uploaded_by: string | null;
  created_at: string;
};

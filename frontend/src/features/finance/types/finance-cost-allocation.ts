/**
 * Finance Cost Allocation types (Costing & Profitability).
 * Mirrors CostAllocationController payloads exactly
 * (Modules\Finance\Presentation\Http\Controllers\CostAllocationController).
 *
 * This engine deliberately never creates a second GL journal — it is a
 * management-dimension attribution only (source Expense → destination
 * Brand/Profit-Center). Money values are numbers — never recalculated in
 * the browser.
 *
 * `destination_profit_center_id` represents a Brand, but Finance has no
 * canonical, queryable Brand directory (a documented upstream gap) — it is
 * carried and displayed as a raw reference id, never resolved to a name.
 */

export type CostAllocationMethod = 'fixed' | 'percentage';

/** V1 sources are always a POSTED Expense; `source_type` is shown verbatim (no enum assumed). */
export type CostAllocation = {
  id: string; // uuid
  source_type: string;
  source_id: string; // Expense uuid
  source_amount: number;
  method: CostAllocationMethod;
  destination_profit_center_id: string; // raw Brand/Profit-Center reference id — no Brand directory
  allocated_amount: number;
  percentage: number | null;
  reverses_allocation_id: string | null;
  created_at: string;
};

export type CostAllocationListParams = { source_id?: string };

export type CostAllocationDestinationInput = {
  profit_center_id: string;
  /** Required when method = 'fixed'. */
  amount?: number;
  /** Required when method = 'percentage'. */
  percentage?: number;
};

export type CostAllocationCreateInput = {
  expense_id: string; // uuid of a POSTED Expense
  method: CostAllocationMethod;
  destinations: CostAllocationDestinationInput[];
};

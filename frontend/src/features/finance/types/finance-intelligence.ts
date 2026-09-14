/**
 * Finance Intelligence types (Costing & Profitability — F5 read-model).
 * Mirror the certified `Modules\Finance\Intelligence` API response shapes
 * exactly:
 *   - ProfitabilityController      / ProfitabilityService
 *   - CostIntelligenceController   / CostIntelligenceService
 *   - CashFlowController           / CashFlowIntelligenceService
 *
 * Entirely read-only — no backend changes. Money values are numbers (company
 * currency default); format via useFormatter().money. `*_pct` fields are
 * already on a 0–100 scale (not 0–1) — format via fmt.percent(value, false),
 * matching how financial-statements-page.tsx renders `net_margin_pct` etc.
 */

// ── Shared reporting window ---------------------------------------------------

/**
 * Optional reporting window. Omitted fields let the backend apply its own
 * default — a trailing 12 months ending today
 * (Concerns\ResolvesFinanceContext::financeWindow()).
 */
export type FinanceIntelligenceWindowParams = { from?: string; to?: string };

// ── Profitability (GET /finance/intelligence/profitability/*) ----------------
// Route group: finance/intelligence, gated by permission:finance.analytics.view
// (see the `Route::middleware('permission:finance.analytics.view')->group(...)`
// wrapping every profitability/cost/cash-flow route in routes/api.php).

export type ProfitabilityDimension =
  | 'company'
  | 'branch'
  | 'cost_center'
  | 'project'
  | 'brand'
  | 'customer'
  | 'product'
  | 'channel';

/** GET .../profitability/company — ProfitabilityService::company(). */
export type ProfitabilityCompany = {
  dimension: 'company';
  revenue: number;
  expense: number;
  profit: number;
  margin_pct: number;
};

/**
 * One row of the branch/cost-center/project breakdown
 * (ProfitabilityService::byDimension()). Exactly one of `branch` /
 * `cost_center` / `project` is present, matching the parent's `dimension` —
 * its value is the raw ledger dimension id (branch_id and project_id are
 * uuid columns, cost_center_id is an integer FK — see
 * create_finance_journal_lines_table migration), shown verbatim. Finance does
 * not join branch/cost-center/project names onto this row (the same
 * ↔-other-module boundary as AP's `supplier_id`-only rows).
 */
export type ProfitabilityDimensionRow = {
  branch?: string;
  cost_center?: string;
  project?: string;
  revenue: number;
  expense: number;
  profit: number;
  margin_pct: number;
};

/** GET .../profitability/{branch,cost-center,project}. */
export type ProfitabilityByDimension = {
  dimension: 'branch' | 'cost_center' | 'project';
  rows: ProfitabilityDimensionRow[];
};

/**
 * One row of the Brand breakdown (ProfitabilityService::byBrand()) — the
 * approved FIN-04 convention where `profit_center_id` on a journal line IS a
 * Brand's own id. `brand_name`/`brand_code` are null, and `resolved` is
 * false, when the id does not resolve to a current Brand of this company
 * (deleted, or from another company) — the amount is still real and still
 * counted in `total`; render the row honestly as unresolved, never drop it.
 */
export type ProfitabilityBrandRow = {
  brand_id: string;
  brand_name: string | null;
  brand_code: string | null;
  resolved: boolean;
  revenue: number;
  expense: number;
  profit: number;
  margin_pct: number;
};

/** Shared shape of the `unallocated` and `total` figures on {@link ProfitabilityByBrand}. */
export type ProfitabilityAmountSummary = {
  revenue: number;
  expense: number;
  profit: number;
  margin_pct: number;
};

/**
 * GET .../profitability/brand — ProfitabilityService::byBrand(). Unlike the
 * other dimension breakdowns, this one reconciles exactly:
 * `sum(rows) + unallocated == total`, within the same revenue/expense
 * category scope every profitability figure here uses (not the wider
 * company() net-profit figure, which also includes other_revenue/expense).
 * `unallocated` is the GL activity with no Brand dimension at all
 * (`profit_center_id IS NULL`) — historical data before this task, and any
 * non-Commerce posting that still doesn't carry one. It is a reporting
 * classification only, never a synthetic Brand.
 */
export type ProfitabilityByBrand = {
  dimension: 'brand';
  rows: ProfitabilityBrandRow[];
  unallocated: ProfitabilityAmountSummary;
  total: ProfitabilityAmountSummary;
};

export type ProfitabilityCustomerRow = {
  /** uuid — an opaque party reference (finance_customer_invoices.customer_id). */
  customer_id: string;
  revenue: number;
  estimated_profit: number;
  margin_pct: number;
};

/**
 * GET .../profitability/customer — an ATTRIBUTION, not a ledger-native split:
 * AR revenue per customer with the company's operating margin applied.
 * `attribution` states the method verbatim (ProfitabilityService::byCustomer());
 * render it, don't hide it.
 */
export type ProfitabilityByCustomer = {
  dimension: 'customer';
  attribution: string;
  rows: ProfitabilityCustomerRow[];
};

/**
 * GET .../profitability/{product,channel} — ProfitabilityService::byUntaggedDimension().
 * The ledger does not tag journal lines by product or channel, so the backend
 * returns `available: false` with the company-level total rather than a
 * fabricated split. This is a deliberate, permanent design choice (not a bug,
 * not a loading/empty state) — render it as an explicit "not yet available"
 * state, never as an empty chart.
 */
export type ProfitabilityUnavailable = {
  dimension: 'product' | 'channel';
  available: false;
  note: string;
  company: ProfitabilityCompany;
};

export type ProfitabilityResult =
  | ProfitabilityCompany
  | ProfitabilityByDimension
  | ProfitabilityByBrand
  | ProfitabilityByCustomer
  | ProfitabilityUnavailable;

// ── Cost intelligence (GET /finance/intelligence/cost/*) ----------------------

export type CostAccountTotal = {
  account_id: number;
  code: string;
  name: string;
  amount: number;
};

/** GET .../cost/breakdown — CostIntelligenceService::breakdown(). Takes from/to. */
export type CostBreakdown = {
  total_cost: number;
  by_category: {
    cost_of_sales: number;
    operating_expense: number;
    other_expense: number;
  };
  by_account: CostAccountTotal[];
};

export type CostOperationalBucketKey =
  | 'manufacturing'
  | 'logistics'
  | 'marketing'
  | 'administrative'
  | 'other';

/**
 * GET .../cost/operational — CostIntelligenceService::operationalClassification().
 * Takes from/to. `rules` is the deterministic keyword table the classifier
 * used (`other` is the leftover bucket and carries no keyword rule) — the
 * split is explainable, not a black box; render `rules` too, not just `buckets`.
 */
export type CostOperationalClassification = {
  buckets: Record<CostOperationalBucketKey, number>;
  rules: Record<'manufacturing' | 'logistics' | 'marketing' | 'administrative', string[]>;
  method: 'deterministic_keyword_classification';
};

export type CostTrendPoint = { month: string; value: number };

/**
 * GET .../cost/trend — CostIntelligenceService::trend(). NOTE: unlike
 * breakdown/operational, this endpoint does NOT take from/to — it takes a
 * `months` count only (CostIntelligenceController::trend() never calls
 * financeWindow()). It is also a simpler envelope than
 * GET /finance/intelligence/trends: just {month,value} points, no
 * last/change_pct/direction/explanation.
 */
export type CostTrend = { series: CostTrendPoint[] };

export type CostTrendParams = { months?: number };

// ── Cash-flow intelligence (GET /finance/intelligence/cash-flow/*) -----------

/**
 * GET .../cash-flow/current — CashFlowIntelligenceService::current().
 * Takes NO query params at all: it always reports "as of today" / the
 * current month-to-date (CashFlowController::current() never reads from/to
 * or any other param from the request).
 */
export type CashFlowCurrent = {
  cash_position: number;
  receivables: number;
  payables: number;
  month_to_date_operating: number;
};

export type CashFlowScheduleMonth = { month: string; amount: number };

/** Both receivable_forecast and payable_forecast share this shape. */
export type CashFlowForecastSchedule = {
  label: 'expected_collection' | 'expected_payment';
  total: number;
  schedule: CashFlowScheduleMonth[];
  method: 'aging_bucket_schedule';
};

export type CashFlowLiquidityMonth = {
  month: string;
  operating_flow: number;
  collections: number;
  payments: number;
  net_flow: number;
  closing_cash: number;
};

export type CashFlowLiquidityProjection = {
  opening_cash: number;
  months: CashFlowLiquidityMonth[];
};

export type CashFlowRiskAlert = {
  key: string;
  severity: 'critical' | 'warning' | string;
  message: string;
};

/**
 * GET .../cash-flow/forecast — CashFlowController::forecast(). Takes a
 * `horizon` (months, default 3) — NOT from/to.
 */
export type CashFlowForecast = {
  liquidity_projection: CashFlowLiquidityProjection;
  receivable_forecast: CashFlowForecastSchedule;
  payable_forecast: CashFlowForecastSchedule;
  risk_alerts: CashFlowRiskAlert[];
};

export type CashFlowForecastParams = { horizon?: number };

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

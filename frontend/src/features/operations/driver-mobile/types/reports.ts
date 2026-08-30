// Driver App Wallet + Reports read models — TASK-DRIVER-APP-PHASE-6-WALLET-REPORTS-CLOSURE-001.
// These mirror the canonical driver-scoped read contract (GET /driver/wallet, /driver/reports/*,
// /driver/statement). Every value is server-derived; the frontend never aggregates cross-trip.

export type ReportPeriod =
  | 'today'
  | 'this_week'
  | 'this_month'
  | 'previous_month'
  | 'this_year'
  | 'ytd'
  | 'previous_year'
  | 'custom';

export interface ReportPeriodValue {
  period: ReportPeriod;
  /** Only meaningful for `custom`. ISO Y-m-d. */
  from?: string;
  to?: string;
}

export type SettlementRollupStatus = 'needs_review' | 'under_review' | 'disputed' | 'settled';

export interface UnavailableSection {
  available: boolean;
  reason?: string;
  items?: unknown[];
}

export interface DriverWallet {
  period: { from: string; to: string };
  trips: number;
  collections: {
    total: number;
    cash: number;
    transfer: number;
    card: number;
    already_paid: number;
  };
  cash: {
    expected: number;
    submitted: number | null;
    difference: number | null;
    is_balanced: boolean | null;
  };
  settlement_status: SettlementRollupStatus;
  advances: UnavailableSection;
  expenses: UnavailableSection;
  liability: UnavailableSection;
  closing: {
    all_trips_closed: boolean;
    deliveries_outstanding: number;
    custody_remaining: number;
    custody_reconciled: boolean;
    settlement_status: SettlementRollupStatus;
    settlement_complete: boolean;
  };
}

export interface OrdersReportSummary {
  received: number;
  delivered: number;
  partial: number;
  failed: number;
  returned: number;
  skipped: number;
  pending: number;
  deferred: number;
  delivery_rate: number;
}

export interface DriverOrderRow {
  order_id: number | null;
  order_number: string | null;
  customer_name: string | null;
  area: string | null;
  governorate: string | null;
  outcome: string;
  order_value: number | null;
}

export interface DriverOrdersReport {
  period: { from: string; to: string };
  summary: OrdersReportSummary;
  items: DriverOrderRow[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export interface DriverGoodsRow {
  product_id: string;
  product_name: string;
  sku: string;
  received: number;
  delivered: number;
  returned: number;
  damaged: number;
  shortage: number;
  remaining_custody: number;
}

export interface DriverGoodsMovement {
  period: { from: string; to: string };
  arithmetic: string;
  products: DriverGoodsRow[];
}

export interface DriverShortageRow {
  date: string | null;
  product_id: string;
  sku: string;
  expected_return: number;
  actual_return: number;
  damaged: number;
  shortage_qty: number;
  damage_reason: string | null;
  investigation_status: 'under_investigation' | 'reviewed';
  value: number | null;
  liability_status: string | null;
}

export interface DriverShortageReport {
  period: { from: string; to: string };
  items: DriverShortageRow[];
  value_available: boolean;
  liability_ladder_available: boolean;
  note: string;
}

export interface DriverAdvancesReport {
  available: boolean;
  reason: string;
  items: unknown[];
}

export interface DriverStatement {
  month: string;
  period: { from: string; to: string };
  orders: OrdersReportSummary;
  collections: DriverWallet['collections'];
  cash: DriverWallet['cash'];
  settlement_status: SettlementRollupStatus;
  shortages_count: number;
  advances: UnavailableSection;
  expenses: UnavailableSection;
}

// Driver Day Settlement / تقفيل اليوم — types.
//
// These mirror the read-only aggregation API (GET /api/logistics/distribution/driver-settlement
// and /{assignmentId}), which is a per-driver/per-day ROLLUP over the canonical per-trip
// settlement engine and the canonical vehicle-custody / shift-reconciliation engines — NOT a
// new settlement entity. Every money figure originates from the canonical SettlementService;
// every goods/damage/shortage figure from the reconciliation authority. The frontend never
// re-derives, and never invents an unavailable figure.

/** Aggregate money-settlement status for a driver, from the canonical SettlementStatus. */
export type DaySettlementStatus = 'needs_review' | 'under_review' | 'disputed' | 'settled';

/** Derived operational closing stage (read-only rollup label over canonical facts). */
export type ClosingStage =
  | 'open_custody'
  | 'in_operation'
  | 'ready_for_return'
  | 'warehouse_counting'
  | 'needs_review'
  | 'ready_for_closing'
  | 'closed';

/** Canonical end-of-shift reconciliation status (null when no shift was opened). */
export type ReconciliationStatus = 'open' | 'completed' | 'approved' | 'disputed' | null;

export type BoardScope = 'day' | 'active' | 'history';

/** Which money semantics the board's order-value figures carry, declared by the server so the UI
 *  never guesses: whole-order `Order.total`, or one Brand's attributable `order_lines.line_total`. */
export type OrderValueBasis = 'order_total' | 'brand_line_total';

/** The 8 canonical operational KPIs over the visible Active custodies. Expenses/Net Cash are null
 *  when no canonical Driver cash-movement authority exists → rendered "Not available", never zero. */
export interface DaySettlementKpis {
  total_orders: number;
  total_delivered: number;
  /** Canonical DeliveryStopStatus::Failed only — NOT returned, NOT skipped. */
  total_failed: number;
  /** Canonical DeliveryStopStatus::Returned — a distinct outcome, never a renamed Failed. */
  total_returned?: number;
  /** Canonical DeliveryStopStatus::Skipped — a distinct settled non-delivered outcome. */
  total_skipped?: number;
  /** The disjoint union failed + returned + skipped. A stop holds one status, so nothing is double
   *  counted; the three components above stay separately reported. */
  total_undelivered?: number;
  /** Percentage 0–100 (delivered / orders), computed server-side. */
  delivery_rate: number;
  /** Commercial ORDER VALUE for exactly the populations counted above (never collected cash). */
  total_orders_value?: number;
  total_delivered_value?: number;
  total_failed_value?: number;
  total_returned_value?: number;
  total_skipped_value?: number;
  total_undelivered_value?: number;
  total_sales: number;
  total_transfers_paid: number;
  /** Approved cash-out expenses (canonical DriverTripMovement). Real, no longer "Not available". */
  total_expenses: number | null;
  /** Physical cash collected + approved cash-in − approved cash-out. */
  net_cash: number | null;
  /** Approved cash-in (advances). Never folded into expenses. */
  total_cash_in?: number | null;
}

export interface DaySettlementDriverRow {
  assignment_id: number;
  operational_date: string;
  /** Canonical identity of the row — the ONE open Trip/Custody it represents (§7). */
  trip_id: string;
  trip_number: string | null;
  trip_status: string;
  /** When real goods custody began (display only; the identity is the trip, so it survives midnight, §8). */
  custody_started_at: string | null;
  /** True when this driver holds more than one open custody — an invariant violation to review, never hidden (§13). */
  duplicate_open_custody: boolean;
  finalized_at: string | null;
  driver_id: number | null;
  driver_name: string | null;
  vehicle_id: number | null;
  vehicle_plate: string | null;
  trip_ids: string[];
  orders: number;
  delivered: number;
  partial: number;
  /** Canonical DeliveryStopStatus::Failed only. */
  failed: number;
  /** Canonical DeliveryStopStatus::Returned delivery-stop OUTCOME — distinct from `returns` below,
   *  which is the canonical TripReturn goods authority. The two are never conflated. */
  returned_orders?: number;
  skipped?: number;
  /** failed + returned_orders + skipped (disjoint statuses). */
  undelivered?: number;
  delivery_pct: number;
  /** Canonical TripReturn goods-return record count (NOT a delivery-stop outcome). */
  returns: number;
  cash_expected: number;
  transfers: number;
  difference: number | null;
  /** Canonical operational value columns (final workspace table). Whole-order `Order.total` by
   *  outcome, or one Brand's attributable line value — see `value_basis` on the board. */
  orders_value: number;
  delivered_value: number;
  partial_value?: number;
  failed_value: number;
  returned_orders_value?: number;
  skipped_value?: number;
  undelivered_value?: number;
  /** Actual delivered/sold value (= delivered_value), not total assigned order value. */
  total_sales: number;
  /** Non-physical-cash settled: bank transfer + card + prepaid. Excludes physical cash. */
  transfers_paid: number;
  damaged_qty: number;
  shortage_qty: number;
  /** ALWAYS the driver's CURRENT physical on-hand stock in the canonical Driver / Vehicle Warehouse
   *  custody (SUM of VehicleInventoryItem.quantity_on_hand). Never order / undelivered / planned
   *  quantity, never settlement arithmetic. A Brand selection does NOT alter it. */
  goods_on_hand: number;
  /** Brand-scoped slice of that same canonical custody stock; present only while a Brand is
   *  selected. Additive context — the overall figure above stays the primary Goods Remaining. */
  brand_goods_on_hand?: number | null;
  /** Operational cash movements (canonical DriverTripMovement, approved only). */
  cash_collected: number;
  expenses: number;
  cash_in: number;
  net_cash: number;
  pending_movements: number;
  reconciliation_status: ReconciliationStatus;
  settlement_status: DaySettlementStatus;
  closing_stage: ClosingStage;
}

export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface DaySettlementBoard {
  scope: BoardScope;
  date?: string;
  range?: { from: string; to: string };
  /** The canonical Brand the board is narrowed to, or null for All Brands. */
  brand_id?: string | null;
  value_basis?: OrderValueBasis;
  kpis: DaySettlementKpis;
  drivers: DaySettlementDriverRow[];
  meta?: PaginationMeta;
}

export interface DaySettlementTripRow {
  id: string;
  trip_number: string | null;
  /** Canonical TripStatus + operational day, for the Driver/Trip context panel. */
  trip_status?: string;
  operational_date?: string;
  settlement_status: string | null;
  cash_expected: number;
  difference: number | null;
  stops_total: number;
  stops_outstanding: number;
}

export interface DaySettlementOrderRow {
  order_id: string;
  order_number: string | null;
  customer_name: string | null;
  order_value: number | null;
  /** Commercial value that reached the customer — only a Delivered stop has one. Null for
   *  Partial: a per-line delivered authority would be needed, and guessing is worse than null. */
  delivered_value?: number | null;
  payment_method: string | null;
  /** Mutually exclusive canonical buckets — a payment appears in exactly one of these. */
  cash_collected?: number;
  electronic_collected?: number;
  already_paid?: number;
  /** Immutable per-stop handoff snapshot; null when the stop predates snapshot tracking. */
  expected_collection?: number | null;
  collected_from_customer?: number;
  outstanding?: number | null;
  status: string;
}

export interface DaySettlementTransferRow {
  order_id: string | null;
  order_number: string | null;
  customer_name: string | null;
  amount: number;
  payment_type: string;
  payment_label: string;
  collection_status: string;
  reference_number?: string | null;
  /** True for every row here: these are collections the DRIVER recorded against a stop during
   *  custody. Pre-dispatch `already_paid` value is excluded from this list by the server. */
  is_driver_collected?: boolean;
  collected_at?: string | null;
  verified_at?: string | null;
  /** Canonical payment_proofs record, matched by order_id only (the two stores stay separate). */
  proof: { id: string; state: string } | null;
}

export interface DaySettlementReturnRow {
  order_id: string | null;
  product_id?: string | number | null;
  product_name: string | null;
  kind: string;
  dispatched_qty?: number | null;
  returned_qty: number;
  warehouse_confirmed_qty: number | null;
  discrepancy_qty?: number | null;
  reason?: string | null;
  /** Where the goods went / how they were held — canonical reverse-custody fields. */
  disposition?: string | null;
  custody_type?: string | null;
  warehouse_confirmed_at?: string | null;
  /** Reported exactly as the canonical record states it. Never inferred, never created here. */
  driver_liable: boolean;
  confirmed: boolean;
}

export interface DaySettlementGoodsRow {
  product_id: number | string;
  product_name: string | null;
  quantity_on_hand: number;
}

/** The commercial summary — every figure canonical; expected_collection explicitly unavailable. */
export interface DaySettlementCollections {
  cash: number;
  bank_transfer: number;
  card: number;
  /** Canonical driver-collected InstaPay / Wallet — the ACTUAL collection channel, never
   *  derived from an order's declared payment method. */
  instapay?: number;
  wallet?: number;
  already_paid: number;
  total_collected: number;
  delivered_sales: number;
  actual_collected: number;
  cash_expected: number;
  actual_cash: number | null;
  expected_collection: number | null;
  expected_collection_available: boolean;
  collection_difference: number | null;
  /** True only when the handoff snapshot exists but at least one delivery is still Pending/
   *  InProgress — the difference is not yet final and must not be shown as a shortage. */
  collection_difference_pending: boolean;
  /** Driver-collected electronic (bank transfer + card) and the full driver-collected total
   *  (cash + electronic). Excludes prepaid value, which is reported separately below. */
  driver_collected_electronic?: number;
  driver_collected_total?: number;
  prepaid_before_delivery?: number;
  /** Channel availability, reported by the server. `PaymentType` now carries InstaPay and Wallet
   *  as first-class driver-collected channels, so both flags are true and the KPI cards show real
   *  figures. Historical rows recorded before the extension remain bank_transfer / card and are
   *  never reinterpreted — those totals stay visible in Transfers and Reconciliation. */
  channel_granularity?: string;
  instapay_available?: boolean;
  wallet_available?: boolean;
}

/** Aggregate vehicle-custody reconciliation summary (§8). */
export interface DaySettlementCustodySummary {
  reconciliation_available: boolean;
  reconciliation_status: ReconciliationStatus;
  total_loaded: number;
  total_delivered: number;
  expected_return: number;
  actual_return: number;
  accepted: number;
  damaged: number;
  shortage: number;
  remaining_on_hand: number;
  lines_total: number;
  lines_received: number;
}

/** Per-product reconciliation row (§9). `source` distinguishes reconciled vs custody-only. */
export interface DaySettlementProductRow {
  product_id: string;
  product_name: string;
  loaded: number;
  delivered: number;
  expected_return: number;
  actual_good_return: number | null;
  actual_return: number | null;
  damaged: number | null;
  shortage: number | null;
  variance: number | null;
  remaining?: number;
  reconciliation_status: string;
  warehouse_received: boolean;
  source: 'reconciliation' | 'custody';
  /** The canonical warehouse-receive target for this line, or null when there is nothing to
   *  receive against yet (already received, or no reconciliation line opened for this item). */
  receipt: { session_id: string; assignment_id: string; line_id: string } | null;
}

export interface DaySettlementDamage {
  available: boolean;
  gap: string;
  items: { product_name: string; quantity: number; reason: string | null; warehouse_receipt_at: string | null }[];
}

export interface DaySettlementShortage {
  available: boolean;
  gap: string;
  liability_confirmed: boolean;
  items: { product_name: string; variance: number; reconciliation_status: string; resolution: string | null }[];
}

export interface DaySettlementReadiness {
  ready: boolean;
  blockers: string[];
}

/** A driver trip operational cash movement (canonical DriverTripMovement) — TASK-...-APPROVAL-001. */
export type MovementCategory = 'fuel' | 'road_toll' | 'advance' | 'other';
export type MovementDirection = 'cash_out' | 'cash_in';
export type MovementStatus = 'pending' | 'approved' | 'rejected' | 'settled';

export interface DaySettlementMovement {
  id: string;
  category: MovementCategory;
  direction: MovementDirection;
  is_expense: boolean;
  amount: number;
  note: string | null;
  status: MovementStatus;
  occurred_at: string | null;
  has_receipt: boolean;
  reviewed_by: string | null;
  reviewed_at: string | null;
}

export interface DaySettlementMovements {
  available: boolean;
  items: DaySettlementMovement[];
  pending_count: number;
  approved_expenses: number;
  approved_cash_in: number;
  expenses_by_category: Record<string, number>;
}

export interface DaySettlementTimelineEvent {
  code: string;
  at: string;
}

/** Breaks down `overview.failed` by reason. Always sums to `overview.failed`. */
export interface DaySettlementFailedBreakdown {
  no_answer: number;
  postponed: number;
  other: number;
}

/**
 * Rollup of Treasury's confirmation of this driver's Trip cash handovers for the day
 * (canonical CashHandoverService::confirmReceipt(), via TripCashHandoverConfirmed) — a
 * read-only fact, never a figure this page can act on. `confirmed_count === 0` means no
 * handover has been confirmed yet, which must render as an explicit "not yet confirmed"
 * state, never as a blank or a $0.00 that could be misread as "nothing owed".
 */
export interface DaySettlementCashHandover {
  confirmed_count: number;
  total_confirmed_trips: number;
  total_received_cash: number;
  trip_ids_confirmed: number[];
  last_confirmed_at: string | null;
}

export interface DaySettlementDriverDetail {
  date: string;
  driver: {
    id: number | null;
    name: string | null;
    vehicle_id: number | null;
    vehicle_plate: string | null;
  };
  settlement_status: DaySettlementStatus;
  closing_stage: ClosingStage;
  overview: {
    orders: number;
    delivered: number;
    partial: number;
    failed: number;
    /** Breaks down `failed` by reason — always sums back to it. */
    failed_breakdown: DaySettlementFailedBreakdown;
    returns: number;
    delivery_pct: number;
    trips: number;
  };
  /** Treasury's confirmation of this driver's Trip cash handovers — a sibling rollup to
   *  `overview`/`financial`, not part of either (see `DaySettlementCashHandover`). */
  cash_handover: DaySettlementCashHandover;
  financial: {
    cash_expected: number;
    approved_transfers: number;
    actual_cash: number | null;
    difference: number | null;
    is_balanced: boolean | null;
    /** Operational cash movements (canonical DriverTripMovement, approved only). */
    cash_collected: number;
    expenses: number;
    cash_in: number;
    net_cash: number;
  };
  movements: DaySettlementMovements;
  collections: DaySettlementCollections;
  custody_summary: DaySettlementCustodySummary;
  product_reconciliation: DaySettlementProductRow[];
  damage: DaySettlementDamage;
  shortage_review: DaySettlementShortage;
  closing_readiness: DaySettlementReadiness;
  timeline: DaySettlementTimelineEvent[];
  trips: DaySettlementTripRow[];
  orders: DaySettlementOrderRow[];
  transfers: DaySettlementTransferRow[];
  returns: DaySettlementReturnRow[];
  goods_remaining: DaySettlementGoodsRow[];
}

export interface DaySettlementBoardParams {
  scope: BoardScope;
  date?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
  sort?: 'driver' | 'date' | 'difference' | 'delivery_pct';
  dir?: 'asc' | 'desc';
  search?: string;
  shipping_company_id?: number;
  /** ONE canonical Brand (Organization\Brands uuid) or absent for All Brands. */
  brand_id?: string;
  status?: DaySettlementStatus;
  stage?: ClosingStage;
  has_damage?: boolean;
  has_shortage?: boolean;
  needs_review?: boolean;
}

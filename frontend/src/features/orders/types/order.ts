// ── Date presets ─────────────────────────────────────────────────────────────
export type DatePreset =
  | 'today'
  | 'yesterday'
  | 'last_7_days'
  | 'last_30_days'
  | 'this_month'
  | 'last_month'
  | 'custom';

// ── Reservation Status (TASK-INV-RESERVATION-LIFECYCLE-001) ──────────────────
export type ReservationStatus =
  | 'pending'
  | 'reserved'
  | 'partial_reserved'
  | 'awaiting_stock'
  | 'released'
  | 'transferred'
  | 'consumed'
  | 'failed';

// ── Status ────────────────────────────────────────────────────────────────────
// Canonical lifecycle - ADR-042 (Order FSM V3 Canonical).
// Primary flow: in_progress → confirmed → ready_for_dispatch → out_for_delivery → delivered → final_cash
// Entry states: in_progress (normal) | scheduled (future-dated) | awaiting_payment
// Terminal: delivered, final_cash, cancelled, returned
//
// TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 — `final_cash` is the
// first-ever sanctioned edge OUT of `delivered`, reached only once a driver's
// Trip cash handover is physically confirmed by Treasury (backend
// CashHandoverService::confirmReceipt() via CompleteOrderWorkflow). See the
// backend OrderStatus enum's own docblock for the full rationale.
export type OrderStatus =
  | 'in_progress'
  | 'confirmed'
  | 'ready_for_dispatch'
  | 'out_for_delivery'
  | 'delivered'
  | 'final_cash'
  | 'awaiting_payment'
  | 'awaiting_stock'
  | 'scheduled'
  | 'on_hold'
  | 'cancelled'
  | 'returned';

/**
 * Terminal statuses — an order here has reached its final business state and is no
 * longer "active"/"open". Mirrors the backend's canonical
 * OrderStatus::isTerminal() (ADR-042) — the single source of truth for what counts
 * as terminal; every other status is active by construction (INTEGRATION-GATE-
 * REMEDIATION-001, so an "active order" check never needs its own independent,
 * driftable status list).
 *
 * `final_cash` is included alongside `delivered` — per the backend enum, Final
 * Cash is "fulfilled AND cash-settled", a strictly later terminal state reached
 * only from Delivered, not a separate outcome.
 */
export const TERMINAL_ORDER_STATUSES: ReadonlySet<OrderStatus> = new Set<OrderStatus>([
  'delivered',
  'final_cash',
  'cancelled',
  'returned',
]);

/**
 * Official V3 status display order — applies everywhere in the UI:
 * filters, selector, dashboard, analytics, timeline, toolbar, badges, exports.
 * Do NOT reorder.
 */
export const STATUS_TAB_ORDER: Array<OrderStatus | 'all'> = [
  'all',
  'awaiting_payment',
  'in_progress',
  'confirmed',
  // Schedule sits immediately after Confirm so the tab strip reads in the order
  // operators actually work: enter → confirm → schedule the confirmed order.
  // Display order only — the OrderStatus enum, lifecycle transitions, counts and
  // filtering are untouched.
  'scheduled',
  'awaiting_stock',
  'ready_for_dispatch',
  'out_for_delivery',
  'delivered',
  'final_cash',
  'returned',
  'on_hold',
  'cancelled',
];

// ── Bulk action keys ─────────────────────────────────────────────────────────
// Canonical definition — shared by order-list-toolbar, use-order-labels, and orders-page.
// A5 — every key here must have a real handler in orders-page.tsx's executeBulkAction()
// (or, like `reschedule`, its own dedicated flow). Keys with no canonical backend bulk
// endpoint (unlock_for_edit, move_to_awaiting_payment, start_manufacturing,
// purchase_materials, inspect_return, scrap) were removed rather than left reachable
// as no-ops — those transitions remain available per-order via SmartStatusSelector.
export type BulkActionKey =
  | 'confirm'
  | 'verify_payment'
  | 'move_to_preparation'
  | 'return_to_preparation'
  | 'awaiting_stock'
  | 'retry_reservation'
  | 'resume'
  | 'resume_confirmed'
  | 'dispatch'
  | 'complete_delivery'
  | 'complete'
  | 'delivery_failed'
  | 'reschedule'
  | 'review'
  | 'return'
  | 'return_to_confirmed'
  | 'return_to_stock'
  | 'cancel';

// ── Sub-types ─────────────────────────────────────────────────────────────────
export type OrderChannel  = {
  id: string;
  name: string;
  type: string | null;
  brand_id: string | null;
  /** Resolved via the channel's brand() relation — null until eager-loaded or when the channel has no brand. */
  brand: { id: string; name: string; code: string | null } | null;
};
export type CustomerStats = {
  total_orders: number;
  lifetime_value: number;
  first_order_date: string | null;
  last_order_date: string | null;
};

export type OrderCustomer = {
  id: string;
  code: string;
  name: string;
  phone: string | null;
  mobile: string | null;
  // Extended — present on GET /orders/{id} (detail endpoint only)
  email?: string | null;
  city?: string | null;
  governorate?: string | null;
  area?: string | null;
  address?: string | null;
  notes?: string | null;
  is_active?: boolean;
  created_at?: string | null;
  // total_orders is populated on BOTH endpoints (batched per-page on the list
  // endpoint to avoid an N+1 — see OrderController::index()); the other three
  // fields stay null/0 outside the detail endpoint.
  stats?: CustomerStats | null;
};
export type OrderProduct  = { id: string; sku: string; name: string; image_url: string | null; unit_name?: string | null };
export type OrderFee      = { id: string; name: string; total: number };
export type OrderCoupon   = { id: string; code: string; discount: number };

export type OrderNoteType = 'internal' | 'customer' | 'system';

export type OrderNote = {
  id: string;
  order_id: string;
  type: OrderNoteType;
  content: string;
  user_id: string | null;
  user_name: string | null;
  user_role: string | null;
  is_edited: boolean;
  edited_by_id: string | null;
  edited_by_name: string | null;
  edited_at: string | null;
  created_at: string;
  updated_at: string;
};

export type OrderLine = {
  id: string;
  product_id: string;
  product: OrderProduct | null;
  quantity: number;
  unit_price: number;
  line_total: number;
  // Fulfillment quantities (PART 2)
  reserved_qty: number;
  available_qty: number;
  prepared_qty: number;
  packed_qty: number;
  loaded_qty: number;
  delivered_qty: number;
  returned_qty: number;
  cancelled_qty: number;
  warehouse_name: string | null;
  batch_number: string | null;
  // Manufacturing state
  manufacturing_state?: string | null;
  manufacturing_state_label?: string | null;
  manufacturing_started_at?: string | null;
  manufacturing_completed_at?: string | null;
};

export type OrderLocation = {
  lat: number;
  lng: number;
  label?: string | null;
  set_by?: 'customer' | 'employee' | null;
};

/**
 * The honest outcome of resolving an order's map point
 * (`POST /orders/{id}/resolve-location`). Coordinates win outright; otherwise the
 * complete delivery address is geocoded server-side, and every non-success is a
 * truthful state — never a substitute point.
 */
export type OrderLocationStatus =
  | 'available'
  | 'resolved_from_address'
  | 'geocoding_failed'
  | 'address_unavailable'
  | 'not_configured';

export type ResolvedOrderLocation = {
  status: OrderLocationStatus;
  latitude: number | null;
  longitude: number | null;
  source: string | null;
  address: string | null;
};

// ── Order ─────────────────────────────────────────────────────────────────────
export type Order = {
  id: string;
  channel_id: string | null;
  channel: OrderChannel | null;
  customer_id: string;
  customer: OrderCustomer | null;
  external_order_id: string | null;
  order_number: string;
  order_date: string;
  status: OrderStatus;
  status_label: string;
  source: string | null;
  assigned_warehouse_id: string | null;
  /**
   * The resolved canonical fulfillment warehouse (ADR-027: `orders.assigned_warehouse_id`
   * is the single source of truth). Present whenever the relation is loaded.
   *
   * The UI previously derived the warehouse name from `line.warehouse_name`, a column
   * with no writer anywhere in the backend — it is null on every row, which is why a
   * demonstrably reserved order rendered "Assigned Warehouse: —".
   */
  assigned_warehouse?: { id: string; name: string; code: string | null } | null;
  /** e.g. 'branch_coverage' | 'unassigned' | 'no_branch_coverage' | 'auto_policy' | 'manual_override' | 'channel_default'. */
  warehouse_assignment_source?: string | null;
  /** The specific reason assignment did not resolve (e.g. "No Branch Covers Destination") — prefer over the generic reservation_failure_reason when present. */
  warehouse_assignment_failure_reason?: string | null;
  /**
   * Read-only reference into Distribution's Trip -> DriverVehicleAssignment -> Driver
   * chain. Null when the order has no active trip assignment; Commerce never writes
   * this — Distribution is the sole assignment authority.
   */
  driver?: { id: number; driver_code: string; full_name: string; mobile: string | null } | null;
  inventory_reserved_at: string | null;
  inventory_released_at: string | null;
  inventory_shipped_at: string | null;
  reservation_status: ReservationStatus | null;
  reservation_failure_reason: string | null;
  /**
   * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§15/§28/§34).
   * Machine-readable sub-reason while status = 'on_hold'. Only 'blocked_customer'
   * is written today; null while on hold for any other reason, and null once the
   * order leaves on_hold. Never the sole authority for UI copy — pair with
   * `is_blocked_customer_hold` (derived server-side, live block state) to decide
   * whether "Override Block for This Order" applies.
   */
  hold_reason_code: string | null;
  /** True while hold_reason_code = blocked_customer AND the block is still live (no override yet). */
  is_blocked_customer_hold: boolean;
  subtotal: number;
  shipping_total: number;
  discount_total: number;
  tax_total: number;
  total: number;

  // Canonical financial summary — resolved by API (TASK-007).
  // These unify the WooCommerce and enterprise field families.
  // All display screens read these; never the raw WC fields above.
  products_total: number;
  shipping_amount: number;
  discount_value: number;
  discount_percentage: number | null;
  tax_amount: number;
  grand_total: number;
  deposit_paid: number;
  notes: string | null;
  customer_note: string | null;
  internal_notes: string | null;
  created_by_id: string | null;
  created_by_name: string | null;
  /**
   * Present only on the list/paginate read model (an aggregate `withCount`,
   * not the loaded thread) — absent on the single-order detail fetch, which
   * carries the full `order_notes_list` instead. A list/card row should
   * prefer this over loading every note per row just to show "has a note".
   */
  notes_count?: number;
  order_notes_list: OrderNote[];

  // Billing
  billing_first_name: string | null;
  billing_last_name: string | null;
  billing_company: string | null;
  billing_country: string | null;
  billing_state: string | null;
  billing_city: string | null;
  billing_address_1: string | null;
  billing_address_2: string | null;
  billing_postcode: string | null;
  billing_phone: string | null;
  billing_email: string | null;

  // Shipping address
  shipping_first_name: string | null;
  shipping_last_name: string | null;
  shipping_company: string | null;
  shipping_country: string | null;
  shipping_state: string | null;
  shipping_city: string | null;
  shipping_address_1: string | null;
  shipping_address_2: string | null;
  shipping_postcode: string | null;

  // Payment
  payment_method: string | null;
  payment_method_title: string | null;
  transaction_id: string | null;
  date_paid: string | null;

  // Shipping logistics
  shipping_method: string | null;
  shipping_company_name: string | null;  // carrier name (e.g. "DHL", "Aramex")
  shipping_attempts: number;             // 0 = never attempted
  tracking_number: string | null;

  // Enterprise address fields
  governorate: string | null;
  city: string | null;
  shipping_address: string | null;
  building: string | null;
  floor: string | null;
  apartment: string | null;
  landmark: string | null;
  address_notes: string | null;
  area: string | null;
  google_maps_url: string | null;
  location_source: string | null;

  // Enterprise payment / financial fields
  payment_method_manual: string | null;
  payment_proof_path: string | null;
  /** Orders list read-model only (batched per-page) — null on the single-order detail fetch. */
  payment_proof_required?: boolean | null;
  /** 'none' | 'uploaded' | 'verified' | 'rejected'; null on the single-order detail fetch. */
  payment_proof_state?: 'none' | 'uploaded' | 'verified' | 'rejected' | null;
  shipping_cost: number | null;
  shipping_cost_source: string | null;
  discount_amount: number;
  discount_type: string | null;
  deposit_amount: number;
  remaining_balance: number;
  /** Derived payment state (deposit vs total). A deposit is PARTIALLY PAID, never PAID. */
  payment_state?: 'unpaid' | 'partially_paid' | 'paid';
  paid_amount?: number;
  outstanding_amount?: number;

  // Delivery scheduling
  requested_delivery_date: string | null;
  preferred_delivery_time: string | null;
  delivery_window_id: string | null;
  delivery_window: string | null;
  delivery_zone_id: string | null;
  delivery_zone: string | null;

  // Location (single location, either from customer or set by employee)
  location: OrderLocation | null;

  // Status tracking (optional — populated when backend supports it)
  customer_confirmed_at?: string | null;
  customer_confirmed_by?: string | null;
  confirmation_result?: 'confirmed' | 'not_answered' | 'rejected' | 'postponed' | null;
  status_entered_at?: string | null;
  status_entered_by?: string | null;
  previous_status?: OrderStatus | null;

  // Line items
  fees: OrderFee[];
  coupons: OrderCoupon[];
  lines: OrderLine[];

  // Workflow contract — backend is the single source of truth (TASK-ORDER-WORKFLOW-STATUS-API-REFINEMENT-001)
  // Frontend must never hardcode transitions, workflow names, or action keys.
  current_status: string;
  current_status_label: string;
  allowed_status_transitions: Array<{
    target_status: string;  // business state — use as Select value
    label: string;          // human-readable — display to user
    requires_reason: boolean;
    /** TASK-...-SCHEDULED-LIFECYCLE-002 (§7) — UI must collect a future
     *  requested_delivery_date before confirming this transition. */
    requires_date: boolean;
    action: string;         // opaque audit field — frontend must NOT route on this
  }>;

  created_at: string | null;
  updated_at: string | null;
};

// ── Payloads ──────────────────────────────────────────────────────────────────
export type OrderLinePayload = {
  product_id: string;
  quantity: number;
  unit_price: number;
};

export type OrderPayload = {
  channel_id?: string | null;
  customer_id: string;
  external_order_id?: string | null;
  order_date: string;
  status: OrderStatus;
  notes?: string | null;
  lines: OrderLinePayload[];
};

// ── Query params ──────────────────────────────────────────────────────────────
export type OrderSortField = 'order_number' | 'order_date' | 'status' | 'total' | 'created_at';
export type SortDirection  = 'asc' | 'desc';

// DD-025 — Customer Intelligence filter options
export type CustomerIntelligenceFilter =
  | 'first_order'
  | 'repeated'
  | 'more_than_5'
  | 'more_than_10'
  | 'has_cancelled'
  | 'has_returned'
  | 'has_rejected'
  | 'incomplete';

export type OrdersQuery = {
  search?: string;
  status?: OrderStatus | 'all';
  channel_id?: string;
  product_id?: string;
  customer_code?: string;
  phone?: string;
  external_number?: string;
  brand_id?: string;
  sku?: string;
  payment_method?: string;
  payment_status?: 'paid' | 'partial' | 'unpaid';
  has_payment_proof?: boolean;
  // A8 — 'not_reserved' is a query-only convenience grouping (no active hold),
  // not a stored order state; the backend filter resolves it explicitly.
  reservation_status?: ReservationStatus | 'not_reserved';
  shipping_company?: string;
  date_from?: string;
  date_to?: string;
  governorate?: string;
  city?: string;
  zone?: string;
  has_location?: boolean;
  min_amount?: number;
  max_amount?: number;
  min_shipping_attempts?: number;
  min_products?: number;
  customer_filter?: string;   // comma-separated CustomerIntelligenceFilter values
  customer_id?: string;
  created_by?: string;
  page?: number;
  per_page?: number;
  sort_by?: OrderSortField;
  sort_dir?: SortDirection;
};

// ── Response types ────────────────────────────────────────────────────────────
export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  total_amount?: number;
};

export type OrdersResult = {
  items: Order[];
  meta: PaginationMeta;
};

// ── Status counts (for tabs) ──────────────────────────────────────────────────
export type OrderStatusCounts = Partial<Record<OrderStatus | 'all', number>>;

// ── Shipping pricing rules ────────────────────────────────────────────────────
// CD-29 (REMEDIATION-002 §7) — `ShippingPricingRule` and `ShippingCalcResult` were removed
// with the unrouted Shipping Pricing page and its two 404 service methods. The canonical
// order-time shipping contract is `ShippingQuotePayload` / `ShippingQuoteResult` below.

// ── Product pricing (approved price for manual orders) ────────────────────────
export type ProductPricingResult = {
  product_id: string;
  regular_price: number | null;
  sale_price: number | null;
  resolved_price: number | null;
  approved_price: number | null; // alias for resolved_price set by ResolveProductPricingAction
  source: string | null;
  has_pending_review: boolean;
};

// ── Financial snapshot ────────────────────────────────────────────────────────
export type MarginStatus = 'within_target' | 'above_target' | 'below_target';

export type OrderBusinessContextSnapshot = {
  captured_at: string | null;
  brand_context: { name: string | null };
  channel_context: { name: string | null; type: string | null };
  decision_provenance: {
    price: { source: string | null };
    cost: { source: string | null; recipe_version: string | null };
    discount: { source: string | null; manual_override: boolean };
    shipping: { zone: string | null };
  };
  customer_context: {
    delivery_success_rate: number | null;
    tier: string | null;
    segment: string | null;
  };
  policy_versions: { pricing: string | null; shipping: string | null };
  marketing_context: {
    campaign_name: string | null;
    utm_source: string | null;
    utm_medium: string | null;
  };
};

export type OrderFinancialSnapshotLine = {
  id: string;
  product_name: string | null;
  product_sku: string | null;
  quantity: number;
  unit_price_at_sale: number;
  regular_price_at_sale: number | null;
  sale_price_at_sale: number | null;
  line_total: number;
  unit_cost: number | null;
  line_cost: number | null;
  raw_material_cost: number | null;
  packaging_cost: number | null;
  manufacturing_cost: number | null;
  other_cost: number | null;
  gross_profit: number | null;
  margin_percent: number | null;
  margin_status: MarginStatus | null;
  target_margin_percent: number | null;
  source_recipe_version: string | null;
  bom_version_number: number | null;
  price_review_id: string | null;
  price_review_approved_at: string | null;
};

export type OrderFinancialSnapshot = {
  id: string;
  snapshot_uuid: string;
  snapshot_version: number;
  snapshotted_at: string;
  locked: boolean;
  locked_at: string | null;
  hash_verified: boolean | null;
  integrity_hash: string | null;
  currency: string;
  recipe_version: string | null;
  pricing_engine_version: string;
  cost_engine_version: string;
  subtotal: number;
  discount_amount: number;
  discount_type: string | null;
  shipping_cost: number;
  grand_total: number;
  deposit_amount: number;
  remaining_balance: number;
  total_cogs: number | null;
  total_raw_material_cost: number | null;
  total_packaging_cost: number | null;
  total_manufacturing_cost: number | null;
  total_other_cost: number | null;
  gross_profit: number | null;
  actual_margin_percent: number | null;
  margin_status: MarginStatus | null;
  target_margin_percent: number | null;
  margin_difference: number | null;
  shipping_zone: string | null;
  shipping_rule_name: string | null;
  shipping_override_applied: boolean;
  business_context: OrderBusinessContextSnapshot | null;
  lines: OrderFinancialSnapshotLine[];
};

// ── Customer lookup (manual order phone-first resolution) ─────────────────────
export type CustomerLookupStats = {
  total_orders: number;
  delivered: number;
  completed: number;
  cancelled: number;
  returned: number;
  success_rate: number;
  lifetime_value: number;
  avg_order_value: number;
  first_order_date: string | null;
  last_order_date: string | null;
};

export type CustomerAddress = {
  id: string;
  is_default: boolean;
  governorate: string | null;
  city: string | null;
  area: string | null;
  address_line: string | null;
  building: string | null;
  floor: string | null;
  apartment: string | null;
  landmark: string | null;
  address_notes: string | null;
  google_maps_lat: number | null;
  google_maps_lng: number | null;
  google_maps_url: string | null;
  location_source: string | null;
};

export type CustomerLookupCustomer = {
  id: string;
  name: string;
  phone: string | null;
  mobile: string | null;
  governorate: string | null;
  city: string | null;
  area: string | null;
  notes: string | null;
};

export type CustomerLookupResult = {
  customer: CustomerLookupCustomer;
  addresses: CustomerAddress[];
  stats: CustomerLookupStats;
} | null;

// ── Brand Order Policy (from GET /configuration/brands/{id}/policies/order) ───
export type BrandOrderPolicy = {
  source_entry_policies: {
    manual: string | string[];
    pos: string | string[];
    woocommerce: string;
    public_api: string;
  };
  payment_proof_policy: Record<string, 'none' | 'required' | 'optional'>;
  auto_reserve_inventory: boolean;
  customer_matching_policy: 'reuse_existing' | 'warn_only' | 'block_duplicate' | 'always_create_new';
  require_phone: boolean;
  require_address: boolean;
  customer_lookup_enabled: boolean;
  discount_policy: string;
  deposit_policy: string;
};

// ── Shipping quote (POST /shipping/quote) ─────────────────────────────────────
export type ShippingQuotePayload = {
  brand_id: string;
  governorate_id: number;
  city_id?: number | null;
};

/** coverage_status: 'covered' | 'needs_review' | 'unavailable' | 'walk_in' */
export type ShippingQuoteResult = {
  available: boolean;
  decision: string;
  coverage_status: string;
  validation_message: string | null;
  shipping_price: number | null;
  delivery_days: number | null;
  same_day: boolean;
  cod_allowed: boolean;
  preferred_provider: string | null;
  governorate_id: number | null;
  city_id: number | null;
};

// ── Activity timeline ─────────────────────────────────────────────────────────
export type OrderActivityActionType =
  | 'created' | 'updated' | 'deleted'
  | 'workflow' | 'payment' | 'inventory'
  | 'customer' | 'shipping' | 'system'
  | 'automation' | 'note';

export type OrderActivitySource =
  | 'dashboard' | 'mobile_app' | 'api'
  | 'woocommerce' | 'automation' | 'cron' | 'webhook';

export type OrderActivityActorType =
  | 'user' | 'system' | 'api' | 'automation' | 'woocommerce' | 'webhook';

export type OrderActivity = {
  id: string;
  event_type: string;
  description: string;
  actor_id: string | null;
  actor_name: string | null;
  actor_role: string | null;
  actor_email: string | null;
  actor_type: OrderActivityActorType | null;
  source: OrderActivitySource | null;
  action_type: OrderActivityActionType | null;
  previous_value: Record<string, unknown> | null;
  new_value: Record<string, unknown> | null;
  changed_fields: string[] | null;
  reason: string | null;
  ip_address: string | null;
  user_agent: string | null;
  module: string;
  payload: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
};

// ── Manual order payload for POST /orders/manual ──────────────────────────────
export type ManualOrderPayload = {
  company_id?: string | null;
  channel_id?: string | null;
  status?: string;
  order_date?: string | null;
  requested_delivery_date?: string | null;
  delivery_window_id?: string | null;
  delivery_window?: string | null;
  customer_id?: string | null;
  customer_name?: string | null;
  customer_phone?: string | null;
  customer_secondary_phone?: string | null;
  customer_notes?: string | null;
  governorate?: string | null;
  city?: string | null;
  area?: string | null;
  shipping_address?: string | null;
  building?: string | null;
  floor?: string | null;
  apartment?: string | null;
  landmark?: string | null;
  address_notes?: string | null;
  delivery_zone_id?: string | null;
  delivery_zone?: string | null;
  google_maps_lat?: number | null;
  google_maps_lng?: number | null;
  google_maps_url?: string | null;
  location_source?: string | null;
  governorate_id?: number | null;
  city_id?: number | null;
  payment_method_manual?: string | null;
  shipping_cost?: number | null;
  shipping_cost_source?: string | null;
  discount_amount?: number | null;
  discount_type?: string | null;
  deposit_amount?: number | null;
  payment_proof_path?: string | null;
  notes?: string | null;
  /** C1 — explicit opt-in; false unless the operator checks the box. */
  use_as_default_address?: boolean;
  lines: { product_id: string; quantity: number; unit_price: number }[];
};

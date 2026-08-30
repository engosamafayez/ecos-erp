export type DeliveryStopStatus = 'pending' | 'in_progress' | 'delivered' | 'partial' | 'failed' | 'returned' | 'skipped';
export type DeliveryActionType = 'completed' | 'partial' | 'refused' | 'not_available' | 'delay' | 'wrong_address' | 'unreachable';
export type PaymentType = 'cash' | 'bank_transfer' | 'already_paid';
export type PaymentCollectionStatus = 'recorded' | 'pending_verification' | 'verified' | 'rejected';
export type ExceptionType = 'damaged' | 'missing' | 'wrong_product' | 'complaint' | 'packaging' | 'other';
export type ReturnType = 'full' | 'partial';
export type SettlementStatus = 'draft' | 'submitted' | 'verified' | 'closed';

export interface DriverTrip {
  // The canonical driver trip payload — exactly what DriverRuntimeController::tripSummary()
  // emits (10 keys, no more). The previous shape declared 13 fields the backend never
  // sent, which is why the Home rendered NaN. Assigned-order count = stops_count
  // (one delivery stop is one order). Driver identity comes from the auth context, not
  // here; money totals and kpis are intentionally absent (frozen / out of D-01 scope).
  id: string;
  trip_number: string | null;
  status: string;
  company_id: number;
  driver_id: number | null;
  vehicle_id: number | null;
  /** Vehicle identity (read-only) from the canonical Vehicle via the pairing. Null if unpaired. */
  vehicle_plate: string | null;
  vehicle_name: string | null;
  stops_count: number;
  exceptions_count: number | null;
  trip_started_at: string | null;
  trip_finished_at: string | null;
}

export interface DriverTripKpis {
  total_orders: number;
  pending: number;
  delivered: number;
  partial: number;
  failed: number;
  returned: number;
  total_collections: number;
  remaining_stops: number;
}

export interface DeliveryStop {
  id: string;
  sequence: number;
  status: DeliveryStopStatus;
  delivery_type: DeliveryActionType | null;
  collected_amount: number;
  payment_method: string | null;
  attempted_at: string | null;
  completed_at: string | null;
  notes: string | null;
  order: StopOrderSummary;
}

export interface StopOrderSummary {
  id: number;
  order_number: string;
  customer_name: string | null;
  phone: string | null;
  address: string | null;
  governorate: string | null;
  city: string | null;
  area: string | null;
  gps: { lat: number; lng: number } | null;
  payment_method: string | null;
  grand_total: number;
  deposit_paid: number;
  remaining_balance: number;
  items_count: number;
  delivery_notes: string | null;
  // Populated on the stop DETAIL only; the list carries items_count instead.
  lines?: StopOrderLine[];
}

export interface StopOrderLine {
  // Identifies the line for the delivery API (POST /driver/stops/{id}/deliver); allocation_records
  // are keyed by it, so the driver posts the cumulative delivered total per line.
  order_line_id: string;
  product_id: number;
  product_name: string | null;
  ordered_qty: number;
  unit_price: number;
  line_total: number;
  loaded_qty: number;
  delivered_qty: number;
  returned_qty: number;
  remaining_qty: number;
}

export interface PaymentCollection {
  id: number;
  payment_type: PaymentType;
  amount: number;
  reference_number: string | null;
  notes: string | null;
  image_path: string | null;
  status: PaymentCollectionStatus;
  verified_at: string | null;
  created_at: string;
}

export interface DeliveryProof {
  id: number;
  signature_path: string | null;
  photos: string[];
  notes: string | null;
  captured_at: string;
}

export interface DeliveryException {
  id: number;
  exception_type: ExceptionType;
  description: string;
  photos: string[];
  synced_to_cs: boolean;
  resolved_at: string | null;
  resolution_notes: string | null;
  created_at: string;
}

export interface DeliveryReturn {
  id: number;
  order_id: number;
  product_id: number;
  product_name: string;
  return_type: ReturnType;
  returned_qty: number;
  reason: string | null;
  photos: string[];
  warehouse_confirmed_qty: number | null;
  warehouse_confirmed_at: string | null;
  discrepancy_qty: number | null;
  driver_liability: boolean;
  created_at: string;
}

export interface TripSettlement {
  id: number;
  cash_collected: number;
  bank_transfers_pending: number;
  already_paid: number;
  total_collected: number;
  cash_expected: number;
  driver_cash_submitted: number | null;
  discrepancy: number | null;
  status: SettlementStatus;
  finalized_at: string | null;
}

export interface CustodyReturn {
  id: number;
  custody_type: string;
  dispatched_qty: number;
  returned_qty: number | null;
  driver_liable: boolean;
  confirmed_at: string | null;
}

export interface TripTimeline {
  events: TripTimelineEvent[];
}

export interface TripTimelineEvent {
  type: 'trip_started' | 'stop_completed' | 'stop_partial' | 'stop_failed' | 'stop_returned' | 'exception' | 'trip_finished';
  label: string;
  stop_sequence: number | null;
  order_number: string | null;
  timestamp: string;
  notes: string | null;
}

export interface DeliveryStopDetail {
  id: string;
  sequence: number;
  status: DeliveryStopStatus;
  delivery_type: DeliveryActionType | null;
  collected_amount: number;
  payment_method: string | null;
  attempted_at: string | null;
  completed_at: string | null;
  notes: string | null;
  order: StopOrderSummary;
  collections: PaymentCollection[];
  proof: DeliveryProof | null;
}

export const STOP_STATUS_LABELS: Record<DeliveryStopStatus, string> = {
  pending:     'Pending',
  in_progress: 'In Progress',
  delivered:   'Delivered',
  partial:     'Partial',
  failed:      'Failed',
  returned:    'Returned',
  skipped:     'Skipped',
};

export const STOP_STATUS_COLORS: Record<DeliveryStopStatus, string> = {
  pending:     'bg-gray-100 text-gray-700',
  in_progress: 'bg-blue-100 text-blue-700',
  delivered:   'bg-green-100 text-green-700',
  partial:     'bg-amber-100 text-amber-700',
  failed:      'bg-red-100 text-red-700',
  returned:    'bg-purple-100 text-purple-700',
  skipped:     'bg-gray-100 text-gray-500',
};

export const EXCEPTION_TYPE_LABELS: Record<ExceptionType, string> = {
  damaged:       'Damaged Item',
  missing:       'Missing Item',
  wrong_product: 'Wrong Product',
  complaint:     'Customer Complaint',
  packaging:     'Packaging Issue',
  other:         'Other',
};

export const SETTLEMENT_STATUS_LABELS: Record<SettlementStatus, string> = {
  draft:     'Draft',
  submitted: 'Submitted',
  verified:  'Verified',
  closed:    'Closed',
};

export const SETTLEMENT_STATUS_COLORS: Record<SettlementStatus, string> = {
  draft:     'bg-gray-100 text-gray-700',
  submitted: 'bg-blue-100 text-blue-700',
  verified:  'bg-green-100 text-green-700',
  closed:    'bg-gray-100 text-gray-500',
};

// ── Group loading (TASK-DRIVER-WAVE-1 Option 1) ──────────────────────────────

export interface DriverLoadingItem {
  product_id: string;
  product_name: string | null;
  quantity_required: number;
  quantity_prepared: number;
  quantity_loaded: number;
  quantity_remaining: number;
  status: string;

  /** Null until the warehouse has confirmed this product at least once. */
  loading_task_id: string | null;
  warehouse_confirmed_at: string | null;

  /**
   * What THIS DRIVER counted. `null` means "not counted yet" — a different fact from
   * a counted zero, and it must never be rendered as 0.
   */
  quantity_driver_received: number | null;
  driver_confirmed_at: string | null;

  /** Signed: negative = received LESS than the warehouse recorded. Null until counted. */
  difference: number | null;

  /** Derived server-side from the quantities and both confirmation timestamps. */
  workflow_state: DriverLoadingWorkflowState;

  open_adjustment: DriverLoadingOpenAdjustment | null;
}

export type DriverLoadingWorkflowState =
  | 'pending_loading'
  | 'awaiting_driver_confirmation'
  | 'adjustment_requested'
  | 'awaiting_driver_reconfirmation'
  | 'driver_confirmed';

export interface DriverLoadingOpenAdjustment {
  id: string;
  driver_reported_qty: number;
  quantity_before: number;
  reason: string | null;
  requested_at: string | null;
}

export interface DriverLoadingShipment {
  driver_name: string | null;
  orders_count: number;
  loading_complete: boolean;
}

export interface DriverLoadingManifest {
  shipment: DriverLoadingShipment | null;
  items: DriverLoadingItem[];
}

// ── Vehicle inventory (read-only) — TASK-DRIVER-EXPERIENCE-UX-AND-ORDERS-FLOW-REWORK-001 ──
// The driver's OWN vehicle stock, exposed by GET /driver/vehicle-inventory. Mirrors the
// canonical VehicleInventoryItemResource + the VehicleInventoryController summary shape.
export interface VehicleInventoryItemRow {
  id: string;
  product_id: number | string;
  sku_snapshot: string;
  name_snapshot: string;
  status: string;
  quantity_loaded: number;
  quantity_allocated: number;
  quantity_delivered: number;
  quantity_returned: number;
  quantity_on_hand: number;
  quantity_unallocated: number;
  operational_date: string | null;
  last_movement_at: string | null;
}

export interface VehicleInventorySummary {
  vehicle_assignment_id: string | null;
  assignment_number: string | null;
  total_quantity_loaded: number;
  total_quantity_delivered: number;
  total_quantity_returned: number;
  total_quantity_on_hand: number;
  products_count: number;
}

export interface DriverVehicleInventory {
  summary: VehicleInventorySummary;
  items: VehicleInventoryItemRow[];
}

// ── Failure vocabulary (TASK-DRIVER-WAVE-2-PHASE-1, Part B) ──────────────────
// One canonical row from GET /driver/failure-reasons, which is emitted verbatim
// from the backend `FailureReason::catalogue()`. The backend enum is the SINGLE
// vocabulary — the frontend never defines which reasons exist; it only renders a
// localized label for each `value` (falling back to this English `label`).
export interface FailureReasonOption {
  value: string;
  label: string;
  category: string;
  category_label: string;
  is_retryable: boolean;
  requires_address_correction: boolean;
}

// ── Payment-transfer proof (TASK-DRIVER-WAVE-2-PHASE-1, Part C) ──────────────
// The driver may only UPLOAD a proof file; the response echoes the canonical
// `payment_proofs` state (always 'uploaded' for a driver — never verified/settled).
export interface DriverPaymentProofResult {
  state: string;
}

/**
 * What the SECURE delivery-proof endpoint returns. Deliberately carries no storage
 * path: the files live on a private disk and are reachable only through the
 * tenant-scoped download route, never by a client-held path.
 */
export interface DriverDeliveryProofResult {
  id: number | string;
  has_signature: boolean;
  photo_count: number;
  captured_at: string | null;
}

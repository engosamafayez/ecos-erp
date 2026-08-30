/**
 * TASK-T04-T05-SHIPPING-CONVERGENCE-001 — types for the approved Operations\Loading
 * (Stack B) operator flow. These mirror the Laravel Resources served under
 * `/api/loading/*` exactly; there is no `/api/distribution/*` here.
 */

export type LoadingSessionStatus =
  | 'draft'
  | 'open'
  | 'loading'
  | 'loading_complete'
  | 'allocating'
  | 'allocated'
  | 'dispatched'
  | 'closed'
  | 'cancelled'
  | string;

export interface LoadingSession {
  id: string;
  session_number: string;
  status: LoadingSessionStatus;
  session_type: string;
  warehouse_id: string | null;
  operational_date: string;
  vehicles_count: number;
  orders_count: number;
  products_count: number;
  total_units_to_load: number;
  total_units_loaded: number;
  loading_pct: number;
  created_at: string | null;
}

export interface VehicleAssignment {
  id: string;
  assignment_number: string;
  status: string;
  vehicle_id: string;
  vehicle_registration_snapshot: string;
  vehicle_type_snapshot: string;
  orders_count: number;
  dispatched_at: string | null;
  returned_at: string | null;
  reconciled_at: string | null;
}

export interface AllocationRecord {
  id: string;
  status: string;
  order_id: string;
  order_number_snapshot: string | null;
  product_id: string;
  sku_snapshot: string | null;
  quantity_requested: number;
  quantity_allocated: number;
  quantity_delivered: number;
  quantity_remaining: number;
  is_partial: boolean;
  priority_rank: number | null;
}

export interface VehicleInventorySummary {
  vehicle_assignment_id: string;
  assignment_number: string;
  total_quantity_loaded: number;
  total_quantity_delivered: number;
  total_quantity_returned: number;
  total_quantity_on_hand: number;
  products_count: number;
}

export interface VehicleInventoryItem {
  id: string;
  product_id: string;
  sku_snapshot: string | null;
  name_snapshot: string | null;
  status: string;
  quantity_loaded: number;
  quantity_allocated: number;
  quantity_delivered: number;
  quantity_returned: number;
  quantity_on_hand: number;
  quantity_unallocated: number;
}

export interface VehicleInventory {
  summary: VehicleInventorySummary;
  items: VehicleInventoryItem[];
}

export interface ReconciliationLine {
  id: string;
  vehicle_inventory_item_id: string;
  product_id: string;
  sku_snapshot: string | null;
  quantity_loaded: number;
  quantity_delivered: number;
  quantity_returned_expected: number;
  quantity_returned_actual: number;
  /** ADR-015 §6.4: loaded - delivered - returned. */
  variance: number;
  variance_resolution: string | null;
  resolution_notes: string | null;
}

export interface ShiftReconciliation {
  id: string;
  status: string;
  vehicle_assignment_id: string;
  loading_session_id: string;
  vehicle_id: string;
  driver_assignment_id: string;
  operational_date: string;
  total_quantity_loaded: number;
  total_quantity_delivered: number;
  total_quantity_returned: number;
  total_variance: number;
  has_variance: boolean;
  variance_notes: string | null;
  opened_at: string | null;
  completed_at: string | null;
  lines: ReconciliationLine[];
}

/** Per-carrier grouping of a session's orders — read-only Shipping Company visibility (G4). */
export interface ShipmentGroup {
  id: string;
  group_number: string;
  status: string;
  shipping_company_id: string | null;
  zone_id: string | null;
  vehicle_assignments_count: number;
  orders_count: number;
  allocation_coverage_pct: number;
  dispatched_at: string | null;
  completed_at: string | null;
}

/*
 * ── GROUP-GRAIN LOADING (TASK-LOADING-GROUP-GRAIN-READ-AND-EXECUTION-UX-002) ──
 *
 * Served by the thin Loading-side read at `/api/loading/groups*`, which exposes the
 * SAME canonical Group manifest the Distribution route serves, under the permission
 * the warehouse actually holds (`operations.preparation.view`).
 */

/**
 * One Group in the loading list — a subset of the canonical slot summary, plus the
 * transport the server resolved for the whole list in one pass (so the card can say
 * whether execution is possible without a request per Group).
 */
export interface LoadingGroupSummary {
  slot_id: string;
  code: string;
  name: string | null;
  warehouse_id: string;
  zone_names: string[];
  orders_count: number;
  products_count: number;
  transport: LoadingGroupTransport;
}

/** `no_planning_window` is a real answer, distinct from "no groups" (see the page). */
export interface LoadingGroupsResponse {
  resolution: 'resolved' | 'no_planning_window';
  window: { id: string; window_date: string } | null;
  groups: LoadingGroupSummary[];
}

/**
 * One product on a Group's loading manifest.
 *
 * `quantity_prepared` and `quantity_loaded` are DIFFERENT FACTS recorded by different
 * acts — separated in the warehouse vs physically put on the vehicle. Loaded is read
 * from `loading_tasks.quantity_loaded` and is 0 when loading has not started; it is
 * never derived from Prepared.
 *
 * `quantity_remaining` is remaining-to-LOAD (Required − Loaded), which is a different
 * number from the preparation projection's remaining-to-PREPARE (Required − Prepared).
 */
export interface LoadingGroupProduct {
  product_id: string;
  product_name: string | null;
  product_sku: string | null;
  unit_code: string | null;
  unit_symbol: string | null;
  quantity_required: number;
  quantity_prepared: number;
  quantity_loaded: number;
  quantity_remaining: number;
  over_prepared_qty: number;
  /** Canonical `loading_tasks.status`; null means no execution row exists yet. */
  loading_status: string | null;

  /** Null until the warehouse has confirmed at least once. */
  loading_task_id: string | null;
  warehouse_confirmed_at: string | null;
  warehouse_confirmed_by: string | null;

  /**
   * The DRIVER's own count. `null` means "not counted yet" — which is a different
   * fact from a counted zero, and must never be rendered as 0.
   */
  quantity_driver_received: number | null;
  driver_confirmed_at: string | null;
  driver_confirmed_by: string | null;

  /** DERIVED server-side from the quantities and the two confirmation timestamps. */
  workflow_state: LoadingWorkflowState;

  open_adjustment: LoadingOpenAdjustment | null;
}

/**
 * The product's position in the warehouse ↔ driver conversation.
 *
 * Derived by the server, never stored and never recomputed here — a screen that
 * decided this for itself would eventually disagree with the quantities it shows.
 */
export type LoadingWorkflowState =
  | 'pending_loading'
  | 'awaiting_driver_confirmation'
  | 'adjustment_requested'
  | 'awaiting_driver_reconfirmation'
  | 'driver_confirmed';

/** An open driver request awaiting a warehouse decision. */
export interface LoadingOpenAdjustment {
  id: string;
  driver_reported_qty: number;
  quantity_before: number;
  reason: string | null;
  requested_at: string | null;
}

/**
 * Transport attached to the Group. Every part is legitimately null — a Group with no
 * vehicle, driver or trip is a healthy Group, and none of them is ever fabricated.
 */
export interface LoadingGroupTransport {
  trip: { trip_id: string; trip_number: string; status: string } | null;
  vehicle: { plate_number: string | null; name: string | null } | null;
  driver: { full_name: string | null; mobile: string | null } | null;
  has_loading_assignment: boolean;
  /**
   * The canonical `vehicle_assignments.status` once loading has been started.
   *
   * Null means loading has not started. Knowing only `has_loading_assignment` would let
   * the screen announce "Loading in progress" over a shipment that already completed.
   */
  loading_assignment_status: string | null;
}

export interface LoadingGroupTotals {
  required: number;
  prepared: number;
  loaded: number;
  remaining: number;
  over_prepared: number;
}

export interface LoadingGroupDetailResponse {
  group: {
    slot_id: string;
    code: string;
    name: string | null;
    warehouse_id: string;
    window_id: string;
  };
  transport: LoadingGroupTransport;
  totals: LoadingGroupTotals;
  products: LoadingGroupProduct[];
}

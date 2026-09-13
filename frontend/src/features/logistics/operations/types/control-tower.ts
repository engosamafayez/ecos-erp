/**
 * TASK-ECOS-V1.1-OPS-04-TASK2 — Control Tower read-model types.
 *
 * Every field here mirrors a Task 1 backend response exactly (Enterprise
 * SummaryService::shipping/custody/returns/settlement/externalCarrier() and
 * CustodyReturnsMonitoringService::expectedReturns()). Nothing is computed
 * client-side; these types exist to keep the frontend honest about what the
 * backend actually returns, including its null/incomplete states.
 */

export interface ShippingSummary {
  trips: {
    awaiting_loading: number;
    loading_in_progress: number;
    ready_for_dispatch: number;
    dispatch_blocked: number;
    executing: number;
    completed_pending_settlement: number;
    closed: number;
    cancelled: number;
    external_carrier_trips: number;
  };
  groups_awaiting_trip_assignment: number;
  delivery_stops: {
    by_status: {
      pending: number;
      in_progress: number;
      delivered: number;
      partial: number;
      failed: number;
      returned: number;
      skipped: number;
    };
    retryable_failed: number;
  };
  window_order_reconciliation: {
    total: number;
    zoned: number;
    unzoned: number;
    grouped: number;
    ungrouped: number;
  };
}

export interface CustodySummary {
  loaded: number;
  delivered: number;
  remaining_with_driver_vehicle: number;
  returned_by_driver: number;
  received_by_warehouse_accepted: number;
  received_by_warehouse_damaged: number;
  awaiting_warehouse_receipt_lines: number;
  trip_return_discrepancy_qty: number;
}

export interface ReturnsSummary {
  physical_return_confirmed: number;
  physical_return_awaiting_confirmation: number;
  driver_liable_discrepancies: number;
  warehouse_receipt_completed_lines: number;
  warehouse_receipt_awaiting_lines: number;
}

export interface SettlementSummary {
  draft: number;
  submitted: number;
  reconciled: number;
  disputed: number;
  finalized: number;
  /** A real, honest count — never coerced from/to a negative figure. */
  collection_difference_pending: number;
}

export interface ExternalCarrierSummary {
  total_shipments: number;
  tendered_awaiting_status: number;
  not_yet_tendered: number;
  /** Raw carrier vocabulary only — deliberately not remapped client-side. */
  by_raw_status: Record<string, number>;
}

export interface ExpectedReturnRow {
  vehicle_inventory_item_id: string;
  product_id: string;
  product_name: string;
  trip: { id: number; uuid: string; trip_number: string; status: string } | null;
  driver: { id: number; full_name: string; driver_code: string } | null;
  vehicle: { id: number; plate_number: string } | null;
  expected_qty: number;
  /** null = no reconciliation line yet (incomplete linkage) — never 0. */
  accepted_qty: number | null;
  damaged_qty: number | null;
  linkage_state: 'awaiting_reconciliation' | 'reconciled_not_yet_received' | 'received';
  received_at: string | null;
  last_movement_at: string | null;
}

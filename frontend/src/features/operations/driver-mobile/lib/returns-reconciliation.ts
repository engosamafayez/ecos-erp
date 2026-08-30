import type { VehicleInventoryItemRow, VehicleInventorySummary } from '../types/driver-mobile';

/**
 * DRIVER RETURN RECONCILIATION — CANONICAL PRESENTATION DERIVATIONS.
 * TASK-DRIVER-APP-PHASE-5-RETURNS-VEHICLE-RECONCILIATION-001.
 *
 * Pure functions over the canonical driver-scoped Vehicle Inventory read
 * (GET /driver/vehicle-inventory → loaded / delivered / returned / on-hand per product).
 * They derive ONLY presentation:
 *   - Expected Return = max(0, loaded − delivered) — the canonical ADR-015 §6.4 identity,
 *     NOT an arbitrary frontend counter (§1);
 *   - the reconciliation status a driver can see for each product (§4);
 *   - the discrepancy, kept visible and never silently zeroed (§6).
 *
 * They invent NO value, drive NO backend transition, and record NO warehouse receipt —
 * the warehouse is the sole authority for actual received / accepted / damaged / shortage
 * (§3). `received` and `remaining` here are read straight from the canonical custody row,
 * which the warehouse's `ReceiveVehicleReturnAction` reconciles absolutely on receipt.
 */

const EPSILON = 0.0001;

export type ProductReconciliationStatus =
  /** expectedReturn === 0 — everything loaded was delivered; nothing was meant to come back. */
  | 'fully_delivered'
  /** Expected back, but the warehouse has not recorded receipt of any of it yet. */
  | 'awaiting_return'
  /** All expected units have been received back and reconciled by the warehouse. */
  | 'reconciled'
  /** Some received, a residual still stands — a visible discrepancy for the warehouse (§6). */
  | 'partial_return';

export interface ProductReconciliation {
  loaded: number;
  delivered: number;
  /** Expected Return = max(0, loaded − delivered). Canonical identity (ADR-015 §6.4); never a counter. */
  expectedReturn: number;
  /**
   * Warehouse-confirmed actual received back = the canonical custody `quantity_returned`
   * (accepted + damaged, set absolutely by ReceiveVehicleReturnAction). 0 until the
   * warehouse records receipt — the driver never writes this.
   */
  received: number;
  /**
   * Still on the vehicle = canonical `quantity_on_hand` = max(0, loaded − delivered − returned).
   * After the warehouse has recorded receipt, a positive value is the shortage / variance.
   */
  remaining: number;
  status: ProductReconciliationStatus;
  /** received > 0 AND remaining > 0 — a discrepancy the warehouse must resolve; kept visible (§6). */
  hasDiscrepancy: boolean;
}

/**
 * The per-product reconciliation a driver can see, derived purely from the canonical
 * custody quantities. Expected Return is the loaded−delivered identity; the received /
 * remaining values are the warehouse-reconciled custody figures — this function classifies
 * them, it does not compute the receipt.
 */
export function buildProductReconciliation(item: {
  quantity_loaded: number;
  quantity_delivered: number;
  quantity_returned: number;
  quantity_on_hand: number;
}): ProductReconciliation {
  const loaded = item.quantity_loaded ?? 0;
  const delivered = item.quantity_delivered ?? 0;
  const received = item.quantity_returned ?? 0;
  const remaining = item.quantity_on_hand ?? 0;
  const expectedReturn = Math.max(0, loaded - delivered);

  let status: ProductReconciliationStatus;
  if (expectedReturn <= EPSILON) {
    status = 'fully_delivered';
  } else if (received <= EPSILON) {
    status = 'awaiting_return';
  } else if (remaining <= EPSILON) {
    status = 'reconciled';
  } else {
    status = 'partial_return';
  }

  return {
    loaded,
    delivered,
    expectedReturn,
    received,
    remaining,
    status,
    hasDiscrepancy: received > EPSILON && remaining > EPSILON,
  };
}

export interface ReturnTotals {
  /** Σ per-product Expected Return — summed per product so a delivered>loaded row can never net it up (§1). */
  expectedReturn: number;
  received: number;
  remaining: number;
}

/**
 * Trip-scoped return totals, summed from the per-product canonical identities (never from a
 * difference of grand totals, which would let one over-delivered line mask another's expected
 * return). The summary's own `total_quantity_*` fields remain the raw custody totals.
 */
export function buildReturnTotals(items: VehicleInventoryItemRow[]): ReturnTotals {
  return items.reduce<ReturnTotals>(
    (acc, item) => {
      const r = buildProductReconciliation(item);
      return {
        expectedReturn: acc.expectedReturn + r.expectedReturn,
        received: acc.received + r.received,
        remaining: acc.remaining + r.remaining,
      };
    },
    { expectedReturn: 0, received: 0, remaining: 0 },
  );
}

/** True when this shift still has custody expected back or a standing discrepancy (§10 closing gate). */
export function hasOutstandingReturns(summary: VehicleInventorySummary | null | undefined): boolean {
  if (!summary) {
    return false;
  }
  return (summary.total_quantity_on_hand ?? 0) > EPSILON;
}

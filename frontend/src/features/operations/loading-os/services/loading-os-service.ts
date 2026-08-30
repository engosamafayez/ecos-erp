import { api as apiClient } from '@/lib/axios';

import type {
  AllocationRecord,
  LoadingGroupDetailResponse,
  LoadingGroupsResponse,
  LoadingSession,
  ShiftReconciliation,
  ShipmentGroup,
  VehicleAssignment,
  VehicleInventory,
} from '../types/loading-os';

/**
 * TASK-T04-T05-SHIPPING-CONVERGENCE-001 — the ONLY data source for the Stack B
 * operator workspace. Every call targets the approved Operations\Loading backend
 * under `/api/loading/*`. There is no `/api/distribution/*` call here, no mock,
 * and no local computation of a backend fact — deliveries go through the T-09
 * endpoint and variance through the reconciliation endpoints, both server-side.
 */
const BASE = '/loading';

export const loadingOsService = {
  /**
   * Sessions are PAGINATED, and this endpoint alone among its siblings is.
   *
   * The envelope is `{ success, message, data, errors }` and, because the controller
   * paginates, its `data` is itself `{ data: [...], meta: {...} }` — so the array lives
   * one level deeper than for `listAssignments`/`listAllocations`, whose controllers
   * return a bare resource collection.
   *
   * Reading `data.data` here therefore yielded the paginator OBJECT, and the workspace
   * crashed on `sessions.data?.map is not a function`. The fix is at this boundary rather
   * than an `Array.isArray()` guard in the page: the guard would have hidden a contract
   * mismatch and rendered a permanently empty list instead of the sessions.
   *
   * The backend contract is correct and unchanged — it is the standard paginated envelope
   * this API uses everywhere.
   */
  async listSessions(): Promise<LoadingSession[]> {
    const { data } = await apiClient.get<{
      data: { data: LoadingSession[]; meta?: unknown };
    }>(`${BASE}/sessions`, { params: { per_page: 50 } });

    return data.data.data;
  },

  async listAssignments(sessionId: string): Promise<VehicleAssignment[]> {
    const { data } = await apiClient.get<{ data: VehicleAssignment[] }>(
      `${BASE}/sessions/${sessionId}/assignments`,
    );
    return data.data;
  },

  async listAllocations(sessionId: string, assignmentId: string): Promise<AllocationRecord[]> {
    const { data } = await apiClient.get<{ data: AllocationRecord[] }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/allocation`,
    );
    return data.data;
  },

  /** Session-wide allocation read (G3) — every vehicle at once; optional single-order drill-down. */
  async listSessionAllocations(sessionId: string, orderId?: string): Promise<AllocationRecord[]> {
    const { data } = await apiClient.get<{ data: AllocationRecord[] }>(
      `${BASE}/sessions/${sessionId}/allocation`,
      { params: orderId ? { order_id: orderId } : undefined },
    );
    return data.data;
  },

  /** Per-carrier shipment groups for the session — read-only Shipping Company visibility (G4). */
  async listShipmentGroups(sessionId: string): Promise<ShipmentGroup[]> {
    const { data } = await apiClient.get<{ data: ShipmentGroup[] }>(
      `${BASE}/sessions/${sessionId}/shipment-groups`,
    );
    return data.data;
  },

  /**
   * Record a LOADED quantity for one planned product on a vehicle. The server fails
   * closed on over-load (loaded > planned/allocated) — see LoadProductAction (G1).
   */
  async loadProduct(
    sessionId: string,
    assignmentId: string,
    payload: {
      pool_entry_id: string;
      product_id: string;
      sku_snapshot: string;
      name_snapshot: string;
      preparation_wave_id: string;
      quantity_planned: number;
      quantity_loaded: number;
      requires_refrigeration?: boolean;
      short_reason?: string;
      notes?: string;
    },
  ): Promise<{ id: string; status: string; quantity_loaded: number; quantity_short: number }> {
    const { data } = await apiClient.post<{ id: string; status: string; quantity_loaded: number; quantity_short: number }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/load-product`,
      payload,
    );
    return data;
  },

  async getInventory(sessionId: string, assignmentId: string): Promise<VehicleInventory> {
    const { data } = await apiClient.get<{ data: VehicleInventory }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/inventory`,
    );
    return data.data;
  },

  /** T-09: record the ACTUAL delivered quantity for one allocation line. Absolute. */
  async recordDelivery(
    sessionId: string,
    assignmentId: string,
    payload: { allocation_record_id: string; quantity_delivered: number; actor_type?: 'driver' | 'dispatcher' },
  ): Promise<AllocationRecord> {
    const { data } = await apiClient.post<{ data: AllocationRecord }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/allocation/deliver`,
      payload,
    );
    return data.data;
  },

  /** Current reconciliation read model, or null if the shift was never opened. */
  async getReconciliation(sessionId: string, assignmentId: string): Promise<ShiftReconciliation | null> {
    const { data } = await apiClient.get<{ data: ShiftReconciliation | null }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/reconciliation`,
    );
    return data.data;
  },

  /** Open/refresh the shift reconciliation; recomputes loaded − delivered − returned. */
  async openReconciliation(sessionId: string, assignmentId: string): Promise<ShiftReconciliation> {
    const { data } = await apiClient.post<{ data: ShiftReconciliation }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/reconciliation/open`,
    );
    return data.data;
  },

  /** Record the quantity physically counted back into the warehouse for one line. */
  async recordReturn(
    sessionId: string,
    assignmentId: string,
    lineId: string,
    payload: { quantity_returned_actual: number; resolution_notes?: string },
  ): Promise<ShiftReconciliation> {
    const { data } = await apiClient.post<{ data: ShiftReconciliation }>(
      `${BASE}/sessions/${sessionId}/assignments/${assignmentId}/reconciliation/lines/${lineId}/return`,
      payload,
    );
    return data.data;
  },

  /**
   * The operative cycle's Groups — the workspace's entry point.
   *
   * A Group is listed because it holds loading-eligible orders. Vehicle, Driver, Trip
   * and Loading Session are not consulted and are not preconditions. This is a READ:
   * calling it creates nothing.
   */
  async listGroups(warehouseId: string | null): Promise<LoadingGroupsResponse> {
    const { data } = await apiClient.get<{ data: LoadingGroupsResponse }>(`${BASE}/groups`, {
      params: warehouseId ? { warehouse_id: warehouseId } : undefined,
    });
    return data.data;
  },

  /**
   * One Group's loading manifest: products with Required / Prepared / Loaded /
   * Remaining, plus whatever transport exists.
   *
   * Served under `operations.preparation.view` rather than the Distribution read
   * permission, which warehouse roles do not hold — same canonical data, a permission
   * boundary only.
   */
  async getGroup(slotId: string): Promise<LoadingGroupDetailResponse> {
    const { data } = await apiClient.get<{ data: LoadingGroupDetailResponse }>(
      `${BASE}/groups/${slotId}`,
    );
    return data.data;
  },

  /**
   * START LOADING — the existing, certified action. No parallel workflow.
   *
   * `POST /logistics/distribution/windows/{w}/slots/{s}/trips/{t}/loading` is the
   * approved entry point: `GroupLoadingContextService::open()` LOCATES the session and
   * vehicle assignment under a lock and creates them only if absent, so pressing twice
   * yields the same two rows rather than a second session.
   *
   * It carries `operations.preparation.update` — the permission the warehouse roles who
   * physically load already hold — which is why this write can be called from the
   * Loading workspace even though the route lives under Distribution.
   *
   * IT DOES NOT LOAD ANYTHING. Opening the session records no quantity; `quantity_loaded`
   * changes only when the warehouse confirms what was physically put on the vehicle.
   */
  async startLoading(
    windowId: string,
    slotId: string,
    tripId: string,
  ): Promise<{ session_id: string; assignment_id: string }> {
    const { data } = await apiClient.post<{
      data: { loading: { session_id: string; assignment_id: string } };
    }>(`/logistics/distribution/windows/${windowId}/slots/${slotId}/trips/${tripId}/loading`);

    return data.data.loading;
  },

  /**
   * The warehouse records and confirms what it physically loaded.
   *
   * Returns the whole refreshed manifest, so the caller never has to reconstruct the
   * new Remaining or workflow state locally — the server recomputes both.
   */
  async confirmLoaded(
    slotId: string,
    productId: string,
    quantityLoaded: number,
    expectedLoadedQty?: number,
  ): Promise<LoadingGroupDetailResponse> {
    const { data } = await apiClient.post<{ data: LoadingGroupDetailResponse }>(
      `${BASE}/groups/${slotId}/products/${productId}/confirm`,
      expectedLoadedQty === undefined
        ? { quantity_loaded: quantityLoaded }
        : { quantity_loaded: quantityLoaded, expected_loaded_qty: expectedLoadedQty },
    );
    return data.data;
  },

  /**
   * The warehouse rules on a driver's request.
   *
   * `reject` deliberately sends no quantity: it declines the request and leaves the
   * canonical Loaded quantity exactly as it was.
   */
  async resolveAdjustment(
    slotId: string,
    adjustmentId: string,
    action: 'accept' | 'edit' | 'reject',
    quantityLoaded?: number,
  ): Promise<LoadingGroupDetailResponse> {
    const { data } = await apiClient.post<{ data: LoadingGroupDetailResponse }>(
      `${BASE}/groups/${slotId}/adjustments/${adjustmentId}/resolve`,
      action === 'edit' ? { action, quantity_loaded: quantityLoaded } : { action },
    );
    return data.data;
  },
};

import { api as apiClient } from '@/lib/axios';

import type {
  AppliedGroupTemplate,
  ApplyGroupTemplatePayload,
  BatchMoveResult,
  CollectResult,
  CurrentWindowResponse,
  DistributionOrder,
  GroupConfiguration,
  GroupFleetOptions,
  GroupLoadingContext,
  GroupRequiredProduct,
  GroupTemplate,
  GroupTemplatesResult,
  GroupTrip,
  GroupTripsResult,
  GroupTripReconciliation,
  OrdersAwaitingGroupResponse,
  GroupVehicleAssignmentResult,
  MapData,
  SaveGroupTemplatePayload,
  TripReadiness,
  ZoneTemplateOwnership,
  SlotSummary,
  UpdateGroupPayload,
  ZoneSummary,
} from '../types';

/**
 * TASK-SHIPPING-DISTRIBUTION-WORKSPACE-UI-E2E-001 — the ONLY data source for the
 * Distribution Workspace.
 *
 * Every method calls the production Laravel API. There is no mock, no fixture,
 * no fallback array and no local computation of a backend fact. If an endpoint
 * fails, the UI surfaces the error rather than substituting invented data.
 */
const BASE = '/logistics/distribution';

export const distributionWorkspaceService = {
  /**
   * Window + zone summaries + slot summaries in one round trip.
   *
   * `warehouseId` scopes the read (D-P5-1). Passing null is the company-wide view
   * the endpoint has always served - but it returns NO operational cycle, because
   * one warehouse's wave cannot speak for the others.
   */
  async getCurrentWindow(warehouseId?: string | null): Promise<CurrentWindowResponse> {
    const { data } = await apiClient.get<{ data: CurrentWindowResponse }>(
      `${BASE}/windows/current`,
      { params: warehouseId ? { warehouse_id: warehouseId } : undefined },
    );
    return data.data;
  },

  async getZones(windowId: string): Promise<ZoneSummary[]> {
    const { data } = await apiClient.get<{ data: ZoneSummary[] }>(
      `${BASE}/windows/${windowId}/zones`,
    );
    return data.data;
  },

  async getSlots(windowId: string): Promise<SlotSummary[]> {
    const { data } = await apiClient.get<{ data: SlotSummary[] }>(
      `${BASE}/windows/${windowId}/slots`,
    );
    return data.data;
  },

  /** Orders in the window, optionally narrowed to one zone or one slot. */
  async getOrders(
    windowId: string,
    params: { zone_id?: number | null; slot_id?: string | null; warehouse_id?: string | null } = {},
  ): Promise<DistributionOrder[]> {
    const query: Record<string, string> = {};
    if (params.warehouse_id) {
      query.warehouse_id = params.warehouse_id;
    }
    if (params.zone_id !== null && params.zone_id !== undefined) {
      query.zone_id = String(params.zone_id);
    }
    if (params.slot_id) {
      query.slot_id = params.slot_id;
    }

    const { data } = await apiClient.get<{ data: DistributionOrder[] }>(
      `${BASE}/windows/${windowId}/orders`,
      { params: query },
    );
    return data.data;
  },

  /**
   * LP-1 — the products required by ONE Distribution Group.
   *
   * This calls the SAME endpoint the architecture audit identified as canonical
   * (`DistributionAggregationService::productAggregation`). No quantity is
   * recomputed here and no second aggregation exists: `slot_id` and
   * `warehouse_id` are passed through, and the server applies the Group scope,
   * the warehouse scope and the Preparation eligibility contract in one query.
   */
  async getGroupRequiredProducts(
    windowId: string,
    slotId: string,
    warehouseId: string | null,
  ): Promise<GroupRequiredProduct[]> {
    const query: Record<string, string> = { slot_id: slotId };
    if (warehouseId) {
      // Belt and braces: a Group belongs to one warehouse, so slot_id alone
      // would scope correctly today. Sending both makes the guarantee explicit
      // rather than incidental — the Part 5B boundary is not left to inference.
      query.warehouse_id = warehouseId;
    }

    const { data } = await apiClient.get<{ data: GroupRequiredProduct[] }>(
      `${BASE}/windows/${windowId}/products`,
      { params: query },
    );
    return data.data;
  },

  /**
   * Set how much of one Product this Group has prepared.
   *
   * ABSOLUTE SET, not an increment: the body carries the new total, so replaying
   * this request writes the same number. That is what makes it idempotent without
   * an idempotency key.
   *
   * The ceiling (`prepared <= required`) is NOT enforced here. Required is live and
   * the client's copy can be stale, so the authoritative check runs inside the
   * server's transaction under the Group's row lock. The response is the Group's
   * whole refreshed list from the server's own presenter — the client re-renders
   * from it rather than patching the row it just sent.
   */
  async setGroupPrepared(
    windowId: string,
    slotId: string,
    productId: string,
    preparedQty: number,
  ): Promise<GroupRequiredProduct[]> {
    const { data } = await apiClient.put<{ data: GroupRequiredProduct[] }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/preparation/${productId}`,
      { prepared_qty: preparedQty },
    );
    return data.data;
  },

  /** The Group's transport execution object(s). Empty until the Group is finalized. */
  /**
   * The Group ↔ Trip difference. A READ: it reports the two set differences and
   * changes nothing. Deliberately a separate call from `getGroupTrips`, so the
   * certified `GroupTrip[]` contract that `finalizeGroup` also returns stays
   * exactly as it is.
   */
  async getGroupReconciliation(
    windowId: string,
    slotId: string,
  ): Promise<GroupTripReconciliation> {
    const { data } = await apiClient.get<{ data: GroupTripReconciliation }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/reconciliation`,
    );
    return data.data;
  },

  /**
   * Orders no Group covers, with the root blocker for each. A READ — it classifies and
   * returns, and assigns nothing.
   *
   * `warehouseId` narrows warehouse-SET orders to that warehouse. Warehouse-NULL orders
   * are returned regardless, because they belong to no warehouse and a filter would drop
   * exactly the rows that need attention.
   */
  async getOrdersAwaitingGroup(
    windowId: string,
    warehouseId?: string | null,
  ): Promise<OrdersAwaitingGroupResponse> {
    const { data } = await apiClient.get<{ data: OrdersAwaitingGroupResponse }>(
      `${BASE}/windows/${windowId}/awaiting-group`,
      { params: warehouseId ? { warehouse_id: warehouseId } : undefined },
    );
    return data.data;
  },

  /**
   * The Group's Trips and, alongside them, the server's readiness decision for each.
   *
   * One request because they are one fact: readiness is computed from these very Trips by
   * the same guards that gate `open()`. Fetching it separately would let the panel and
   * the action disagree about the same Trip.
   */
  async getGroupTrips(windowId: string, slotId: string): Promise<GroupTripsResult> {
    const { data } = await apiClient.get<{
      data: GroupTrip[];
      readiness?: TripReadiness[];
    }>(`${BASE}/windows/${windowId}/slots/${slotId}/trips`);

    return { trips: data.data, readiness: data.readiness ?? [] };
  },

  /**
   * Finalize the Group into its Trip(s).
   *
   * IDEMPOTENT: a second call returns the Trips the first produced rather than
   * creating more, so a retried request after a timeout is safe. The server
   * decides that inside the Group's row lock — the client does not guard it.
   *
   * Writes no order status and no inventory: Finalize is a plan-to-execution
   * handover. Orders move only at Dispatch, which is also the inventory boundary.
   */
  /**
   * Finalize, optionally approving an overflow in the same act.
   *
   * The SAME route as an ordinary Finalize — approving is a qualifier on the Finalize
   * the operator is already performing, not a workflow of its own, so it needs no
   * second endpoint and inherits Finalize's exact permission boundary.
   */
  async finalizeGroup(
    windowId: string,
    slotId: string,
    approveOverflow = false,
  ): Promise<GroupTrip[]> {
    const { data } = await apiClient.post<{ data: GroupTrip[] }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/finalize`,
      approveOverflow ? { approve_overflow: true } : undefined,
    );
    return data.data;
  },

  async getOverflows(windowId: string): Promise<unknown[]> {
    const { data } = await apiClient.get<{ data: unknown[] }>(
      `${BASE}/windows/${windowId}/overflows`,
    );
    return data.data;
  },

  /**
   * Idempotent server-side sweep: bind addresses to cities, collect eligible
   * orders, then re-resolve zones for assignments made before their city was
   * known. Safe to invoke repeatedly — the backend refuses a second assignment
   * for an Order that already has one, and never rebinds a city it already set.
   */
  async collect(): Promise<CollectResult> {
    const { data } = await apiClient.post<{ data: CollectResult }>(`${BASE}/windows/collect`);
    return data.data;
  },

  /**
   * Move SEVERAL orders into one slot, atomically.
   *
   * A separate endpoint rather than a loop over `moveOrderToSlot`: the server runs the
   * whole set in ONE transaction with ONE capacity check, so a destination that cannot
   * take all of them takes none. Looping here would move as many as fit and then fail,
   * which is the outcome this endpoint exists to prevent.
   *
   * Resolves to the server's own summary — `moved`, the destination, and the ids — so the
   * count shown to the operator is the count the server committed, not the count sent.
   */
  async moveOrdersToSlot(
    assignmentIds: string[],
    slotId: string | null,
    reason?: string,
  ): Promise<BatchMoveResult> {
    const { data } = await apiClient.patch<{ data: BatchMoveResult }>(
      `${BASE}/assignments/batch-slot`,
      { assignment_ids: assignmentIds, slot_id: slotId, reason },
    );
    return data.data;
  },

  /**
   * Change ONE order's Distribution Zone — the existing Change Zone contract
   * (`PATCH /assignments/{assignment}/zone`).
   *
   * The backend (`ManualAssignmentService::changeOrderZone`) re-syncs the order's
   * Group from the canonical Order → Zone → Group mapping and enforces the Group
   * capacity guard. A refusal (capacity, cross-warehouse) arrives as a 422 with a
   * message for the operator — this method does not second-guess it.
   */
  async changeOrderZone(
    assignmentId: string,
    zoneId: number,
    reason?: string,
  ): Promise<unknown> {
    const { data } = await apiClient.patch(
      `${BASE}/assignments/${assignmentId}/zone`,
      { zone_id: zoneId, reason },
    );
    return data;
  },

  /**
   * Move ONE order between slots. The backend writes `virtual_slot_id` only —
   * the order's Zone and its Warehouse are untouched by design.
   */
  async moveOrderToSlot(
    assignmentId: string,
    slotId: string | null,
    reason?: string,
  ): Promise<unknown> {
    // The endpoint validates `slot_id` (nullable uuid) — not `virtual_slot_id`,
    // which is the *response* field name. Sending the wrong key validates fine and
    // silently clears the slot; the API feature test is what caught it.
    const { data } = await apiClient.patch(
      `${BASE}/assignments/${assignmentId}/slot`,
      { slot_id: slotId, reason },
    );
    return data;
  },

  /**
   * Create a Distribution Group (a Virtual Capacity Slot).
   *
   * Capacities are deliberately NOT sent: vehicle capacity is a later phase, and
   * a null dimension means "not constrained on this axis" — which is not the same
   * as a capacity of zero.
   */
  async createGroup(
    windowId: string,
    warehouseId: string,
    code: string,
    name?: string,
    /**
     * The Group's maximum order count. `null`/omitted means NO LIMIT, which is
     * not a limit of zero — the same contract the column and the guard carry.
     *
     * ORDER COUNT ONLY. capacity_stops / weight / volume are never sent: nothing
     * enforces them, so setting one would be a limit that silently does nothing.
     */
    capacityOrders?: number | null,
  ): Promise<{ id: string }> {
    const { data } = await apiClient.post<{ data: { id: string } }>(
      `${BASE}/windows/${windowId}/slots`,
      {
        warehouse_id: warehouseId,
        code,
        name: name || null,
        capacity_orders: capacityOrders ?? null,
      },
    );
    return data.data;
  },

  /**
   * Put a Zone into a Distribution Group.
   *
   * The backend re-syncs orders already sitting in that zone, so a group formed
   * after collection still holds them. A zone belongs to at most one group per
   * window — enforced by a unique index, which is what makes an order's group
   * membership unambiguous.
   */
  async addZoneToGroup(windowId: string, slotId: string, zoneId: number): Promise<SlotSummary[]> {
    const { data } = await apiClient.post<{ data: SlotSummary[] }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/zones`,
      { zone_id: zoneId },
    );
    return data.data;
  },

  /** Remove a Zone from a Distribution Group. Orders are untouched. */
  async removeZoneFromGroup(windowId: string, slotId: string, zoneId: number): Promise<SlotSummary[]> {
    const { data } = await apiClient.delete<{ data: SlotSummary[] }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/zones/${zoneId}`,
    );
    return data.data;
  },

  /**
   * Move a Zone between two Groups of the SAME warehouse.
   *
   * Atomic server-side: the zone is re-pointed and this warehouse's orders follow
   * in one transaction, so it never belongs to both groups or to neither.
   */
  async moveZoneToGroup(
    windowId: string,
    toSlotId: string,
    fromSlotId: string,
    zoneId: number,
  ): Promise<SlotSummary[]> {
    const { data } = await apiClient.post<{ data: SlotSummary[] }>(
      `${BASE}/windows/${windowId}/slots/${toSlotId}/zones/move`,
      { zone_id: zoneId, from_slot_id: fromSlotId },
    );
    return data.data;
  },

  /**
   * VP-1 — the tenant-scoped Vehicle and Driver options for a Group.
   *
   * The scoping is the SERVER's: both lists are read through the fleet models'
   * global tenant scopes, so another company's fleet is unreachable rather than
   * merely filtered. No client-side company filter is applied here, because one
   * would imply the server's list could contain foreign rows.
   */
  async getGroupFleetOptions(windowId: string, slotId: string): Promise<GroupFleetOptions> {
    const { data } = await apiClient.get<{ data: GroupFleetOptions }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/fleet-options`,
    );
    return data.data;
  },

  /**
   * VP-1 — assign a Vehicle + Driver to a Group.
   *
   * Sends the cross-module uuids. Capacity (D4-C) and tenancy (S-1…S-6) are
   * decided server-side; a 422 here is the authoritative rejection, and the
   * disabled state in the drawer is only a courtesy on top of it.
   */
  async assignGroupVehicle(
    windowId: string,
    slotId: string,
    vehicleId: string,
    driverId: string,
  ): Promise<GroupVehicleAssignmentResult> {
    const { data } = await apiClient.post<{ data: GroupVehicleAssignmentResult }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/assign-vehicle`,
      { vehicle_id: vehicleId, driver_id: driverId },
    );
    return data.data;
  },

  /**
   * Open (or re-open) the Loading execution context for a Group's Trip.
   *
   * Idempotent server-side: the session and vehicle assignment are LOCATED if
   * they exist and created only if they do not, so calling this twice returns
   * the same two rows rather than a second session. The client therefore does
   * not need to guard against a double click.
   */
  async openGroupLoading(
    windowId: string,
    slotId: string,
    tripId: string,
  ): Promise<GroupLoadingContext> {
    const { data } = await apiClient.post<{ data: GroupLoadingContext }>(
      `${BASE}/windows/${windowId}/slots/${slotId}/trips/${tripId}/loading`,
    );
    return data.data;
  },

  /** Manual Late-Order Assignment — pulls an Order into the window past cutoff. */
  async assignLateOrder(
    windowId: string,
    orderId: string,
    reason?: string,
  ): Promise<unknown> {
    const { data } = await apiClient.post(
      `${BASE}/windows/${windowId}/late-orders`,
      { order_id: orderId, reason },
    );
    return data;
  },
  // ── Group configuration (capacity) ─────────────────────────────────────────

  /**
   * Edit a Group's name and its maximum order count.
   *
   * `capacity_orders: null` REMOVES the limit; omitting the field leaves it
   * alone. The backend refuses a maximum below the group's current order count,
   * and that refusal arrives as a 422 with a sentence for the operator.
   */
  async updateGroup(
    windowId: string,
    slotId: string,
    payload: UpdateGroupPayload,
  ): Promise<GroupConfiguration> {
    const { data } = await apiClient.patch<{ data: GroupConfiguration }>(
      `${BASE}/windows/${windowId}/slots/${slotId}`,
      payload,
    );
    return data.data;
  },

  // ── Map ────────────────────────────────────────────────────────────────────

  /**
   * Zones, groups and plotted orders for the window.
   *
   * Read-only. Coordinates come from the orders themselves; orders without one
   * arrive with `has_location: false` and must stay unplotted.
   */
  async getMap(windowId: string, warehouseId?: string | null): Promise<MapData> {
    const { data } = await apiClient.get<{ data: MapData }>(
      `${BASE}/windows/${windowId}/map`,
      { params: warehouseId ? { warehouse_id: warehouseId } : undefined },
    );
    return data.data;
  },

  // ── Group templates ────────────────────────────────────────────────────────

  /** Every live template for the acting company. Company scope is server-side. */
  /**
   * Templates plus the company's Zone -> Template ownership map.
   *
   * Both come from one request because they are one fact: the server derives ownership
   * with the same method that enforces exclusivity on save.
   */
  async getGroupTemplates(): Promise<GroupTemplatesResult> {
    const { data } = await apiClient.get<{
      data: GroupTemplate[];
      zone_ownership?: ZoneTemplateOwnership[];
    }>(`${BASE}/group-templates`);

    return { templates: data.data, ownership: data.zone_ownership ?? [] };
  },

  async createGroupTemplate(payload: SaveGroupTemplatePayload): Promise<GroupTemplate> {
    const { data } = await apiClient.post<{ data: GroupTemplate }>(
      `${BASE}/group-templates`,
      payload,
    );
    return data.data;
  },

  async updateGroupTemplate(
    templateId: string,
    payload: SaveGroupTemplatePayload,
  ): Promise<GroupTemplate> {
    const { data } = await apiClient.patch<{ data: GroupTemplate }>(
      `${BASE}/group-templates/${templateId}`,
      payload,
    );
    return data.data;
  },

  /** Archive a template. Groups already created from it are untouched. */
  async archiveGroupTemplate(templateId: string): Promise<void> {
    await apiClient.delete(`${BASE}/group-templates/${templateId}`);
  },

  /**
   * Create a NEW Group from a template — configuration only.
   *
   * Nothing runtime is copied: no orders, no vehicle, no driver, no trip, no
   * loading state. Orders appear in the new group the same way they appear in
   * every other group, because its zones are attached to it.
   */
  async applyGroupTemplate(
    windowId: string,
    templateId: string,
    payload: ApplyGroupTemplatePayload,
  ): Promise<AppliedGroupTemplate> {
    const { data } = await apiClient.post<{ data: AppliedGroupTemplate }>(
      `${BASE}/windows/${windowId}/group-templates/${templateId}/apply`,
      payload,
    );
    return data.data;
  },
};

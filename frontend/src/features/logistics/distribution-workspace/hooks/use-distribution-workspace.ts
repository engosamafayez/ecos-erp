import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { preparationService } from '@/features/operations/services/preparation-service';
import { ordersService } from '@/features/orders/services/orders-service';
import { ORDERS_KEY } from '@/features/orders/hooks/use-orders';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { distributionWorkspaceService } from '../services/distribution-workspace-service';
import type {
  ApplyGroupTemplatePayload,
  SaveGroupTemplatePayload,
  UpdateGroupPayload,
} from '../types';

/**
 * TASK-SHIPPING-DISTRIBUTION-WORKSPACE-UI-E2E-001
 *
 * One query-key root for the whole workspace, so any mutation can invalidate the
 * entire surface in a single call. That is deliberate: a slot move changes the
 * source slot, the destination slot, the zone summary, the capacity figures and
 * the KPI totals at once. Invalidating them individually is how counts go stale.
 */
// The warehouse is part of every key: switching warehouse must refetch, not serve
// the previous warehouse's rows from cache. A key that omitted it would show
// warehouse A's orders under warehouse B's header until the cache expired.
const KEYS = {
  all: ['logistics-distribution-workspace'] as const,
  current: (warehouseId: string | null) => [...KEYS.all, 'current', warehouseId] as const,
  orders: (
    windowId: string,
    warehouseId: string | null,
    zoneId: number | null,
    slotId: string | null,
  ) => [...KEYS.all, 'orders', windowId, warehouseId, zoneId, slotId] as const,
  overflows: (windowId: string) => [...KEYS.all, 'overflows', windowId] as const,
  // LP-1 lives UNDER the same root, so the seven existing mutations already
  // refresh it. No mutation was changed and no second sync mechanism exists.
  groupProducts: (windowId: string, slotId: string, warehouseId: string | null) =>
    [...KEYS.all, 'group-products', windowId, slotId, warehouseId] as const,
  // Same root again — Finalize changes the Group's execution state, and the whole
  // workspace refreshes as one surface, as it always has.
  groupTrips: (windowId: string, slotId: string) =>
    [...KEYS.all, 'group-trips', windowId, slotId] as const,
  // Same root once more: assigning a vehicle changes the Group's operational
  // state, its Trip and its remaining capacity together.
  groupFleet: (windowId: string, slotId: string) =>
    [...KEYS.all, 'group-fleet', windowId, slotId] as const,
  // The map is a projection of the SAME window, so it lives under the same root
  // and every existing mutation already refreshes it. No second sync mechanism.
  map: (windowId: string, warehouseId: string | null) =>
    [...KEYS.all, 'map', windowId, warehouseId] as const,
  // Templates are company-scoped CONFIGURATION, not window state, so the key
  // carries no window and no warehouse: switching either must not refetch them
  // and must not serve a different list.
  templates: () => [...KEYS.all, 'group-templates'] as const,
};

/** Window + zones + slots. The single source for the header, KPIs and zone board. */
export function useCurrentDistributionWindow(warehouseId: string | null = null) {
  return useQuery({
    queryKey: KEYS.current(warehouseId),
    queryFn: () => distributionWorkspaceService.getCurrentWindow(warehouseId),
  });
}

export function useDistributionOrders(
  windowId: string | undefined,
  warehouseId: string | null = null,
  zoneId: number | null = null,
  slotId: string | null = null,
  enabled = true,
) {
  return useQuery({
    queryKey: KEYS.orders(windowId ?? '', warehouseId, zoneId, slotId),
    queryFn: () =>
      distributionWorkspaceService.getOrders(windowId as string, {
        zone_id: zoneId,
        slot_id: slotId,
        warehouse_id: warehouseId,
      }),
    enabled: Boolean(windowId) && enabled,
  });
}

export function useDistributionOverflows(windowId: string | undefined) {
  return useQuery({
    queryKey: KEYS.overflows(windowId ?? ''),
    queryFn: () => distributionWorkspaceService.getOverflows(windowId as string),
    enabled: Boolean(windowId),
  });
}

/**
 * LP-1 — Loading Preparation for one Group: its currently required products.
 *
 * Enabled only while the Group's panel is open, so a window with many Groups
 * costs one request per OPENED group rather than one per group that exists.
 */
export function useGroupRequiredProducts(
  windowId: string | undefined,
  slotId: string | undefined,
  warehouseId: string | null,
  enabled = true,
) {
  return useQuery({
    queryKey: KEYS.groupProducts(windowId ?? '', slotId ?? '', warehouseId),
    queryFn: () =>
      distributionWorkspaceService.getGroupRequiredProducts(
        windowId as string,
        slotId as string,
        warehouseId,
      ),
    enabled: Boolean(windowId) && Boolean(slotId) && enabled,
  });
}

/**
 * The Group's Trip(s). Fetched only while the Group's panel is open.
 *
 * Empty until Finalize. A Group may hold several Trips when Trip.capacity forced
 * a split, so this is always a list — never a single object.
 */
/**
 * The Group ↔ Trip difference, fetched only while the Group's panel is open.
 *
 * Keyed alongside the trips it explains, so finalizing — which invalidates the
 * Group's trip queries — refreshes the difference in the same pass.
 */
export function useGroupReconciliation(
  windowId: string | undefined,
  slotId: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: [...KEYS.groupTrips(windowId ?? '', slotId ?? ''), 'reconciliation'] as const,
    queryFn: () =>
      distributionWorkspaceService.getGroupReconciliation(windowId as string, slotId as string),
    enabled: enabled && Boolean(windowId) && Boolean(slotId),
  });
}

/** Orders no Group covers, for the exception surface on the Groups board. */
export function useOrdersAwaitingGroup(
  windowId: string | undefined,
  warehouseId: string | null = null,
) {
  return useQuery({
    queryKey: [...KEYS.all, 'awaiting-group', windowId ?? '', warehouseId ?? ''] as const,
    queryFn: () =>
      distributionWorkspaceService.getOrdersAwaitingGroup(windowId as string, warehouseId),
    enabled: Boolean(windowId),
  });
}

export function useGroupTrips(
  windowId: string | undefined,
  slotId: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: KEYS.groupTrips(windowId ?? '', slotId ?? ''),
    queryFn: () => distributionWorkspaceService.getGroupTrips(windowId as string, slotId as string),
    enabled: Boolean(windowId) && Boolean(slotId) && enabled,
  });
}

/**
 * Finalize the Group into its Trip(s).
 *
 * The NINTH mutation on the same root, invalidated the same way as the other
 * eight — no second synchronisation mechanism. Retrying is safe: the server is
 * idempotent, so a double-click cannot produce two Trips.
 */
export function useFinalizeGroup() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; slotId: string; approveOverflow?: boolean }) =>
      distributionWorkspaceService.finalizeGroup(vars.windowId, vars.slotId, vars.approveOverflow),
    onSuccess: invalidate,
  });
}

/** VP-1 — tenant-scoped Vehicle/Driver options for the assignment drawer. */
export function useGroupFleetOptions(
  windowId: string | undefined,
  slotId: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: KEYS.groupFleet(windowId ?? '', slotId ?? ''),
    queryFn: () =>
      distributionWorkspaceService.getGroupFleetOptions(windowId as string, slotId as string),
    enabled: Boolean(windowId) && Boolean(slotId) && enabled,
  });
}

/**
 * VP-1 — assign a Vehicle + Driver to a Group.
 *
 * Invalidated through the same root as every other workspace mutation, so the
 * Group card, its Trip and the capacity figures refresh as one surface.
 */
export function useAssignGroupVehicle() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: {
      windowId: string;
      slotId: string;
      vehicleId: string;
      driverId: string;
    }) =>
      distributionWorkspaceService.assignGroupVehicle(
        vars.windowId,
        vars.slotId,
        vars.vehicleId,
        vars.driverId,
      ),
    onSuccess: invalidate,
  });
}

/**
 * Open the Loading execution context for a Group's Trip.
 *
 * A mutation rather than a query because it MAY create the session and the
 * vehicle assignment on first call. It is safe to retry — the server locates
 * before it creates.
 */
export function useOpenGroupLoading() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; slotId: string; tripId: string }) =>
      distributionWorkspaceService.openGroupLoading(vars.windowId, vars.slotId, vars.tripId),
    onSuccess: invalidate,
  });
}

/** Everything the workspace shows is derived from these queries — refetch them all. */
function useInvalidateWorkspace() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: KEYS.all });
}

export function useCollectDistribution() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: () => distributionWorkspaceService.collect(),
    onSuccess: invalidate,
  });
}

/**
 * Set the prepared quantity for one Product in one Group.
 *
 * The EIGHTH mutation on the same root, invalidated the same way as the other
 * seven — no second synchronisation mechanism is introduced. Invalidating the root
 * (rather than only this Group's product key) is deliberate: Prepared does not
 * change Required, but the operator's next action is usually a Group change, and
 * the workspace has always refreshed as one surface.
 */
export function useSetGroupPrepared() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: {
      windowId: string;
      slotId: string;
      productId: string;
      preparedQty: number;
    }) =>
      distributionWorkspaceService.setGroupPrepared(
        vars.windowId,
        vars.slotId,
        vars.productId,
        vars.preparedQty,
      ),
    onSuccess: invalidate,
  });
}

/**
 * Create a Distribution Group and put the selected Zones into it.
 *
 * The two API calls are sequential ON PURPOSE: the group must exist before a
 * zone can join it, and each zone assignment re-syncs the orders already in
 * that zone. Failure of a later zone leaves the group and its earlier zones in
 * place, which is recoverable — the operator adds the remaining zone again.
 */
export function useCreateDistributionGroup() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: async (vars: {
      windowId: string;
      warehouseId: string;
      code: string;
      name?: string;
      /** null / omitted = no maximum. Order count only. */
      capacityOrders?: number | null;
      zoneIds: number[];
    }) => {
      const group = await distributionWorkspaceService.createGroup(
        vars.windowId,
        vars.warehouseId,
        vars.code,
        vars.name,
        vars.capacityOrders ?? null,
      );

      for (const zoneId of vars.zoneIds) {
        await distributionWorkspaceService.addZoneToGroup(vars.windowId, group.id, zoneId);
      }

      return group;
    },
    onSuccess: invalidate,
  });
}

/** Remove a Zone from a Group. */
export function useRemoveZoneFromGroup() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; slotId: string; zoneId: number }) =>
      distributionWorkspaceService.removeZoneFromGroup(vars.windowId, vars.slotId, vars.zoneId),
    onSuccess: invalidate,
  });
}

/** Move a Zone between two Groups of the same warehouse. */
export function useMoveZoneToGroup() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; fromSlotId: string; toSlotId: string; zoneId: number }) =>
      distributionWorkspaceService.moveZoneToGroup(
        vars.windowId,
        vars.toSlotId,
        vars.fromSlotId,
        vars.zoneId,
      ),
    onSuccess: invalidate,
  });
}

/** Add an existing Zone to an existing Group. */
export function useAddZoneToGroup() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; slotId: string; zoneId: number }) =>
      distributionWorkspaceService.addZoneToGroup(vars.windowId, vars.slotId, vars.zoneId),
    onSuccess: invalidate,
  });
}

/**
 * TASK-DISTRIBUTION-WAREHOUSE-ASSIGNMENT-RESOLUTION-001
 *
 * Manually assign a warehouse to an Order that has none.
 *
 * ┌─ WHY THERE IS NO NEW ENDPOINT ───────────────────────────────────────────┐
 * │ `POST api/orders/{order}/override-warehouse` already exists, already      │
 * │ carries `permission:sales.orders.update`, and already writes the audit    │
 * │ row through WarehouseAssignmentEngine::override(). This hook is a client  │
 * │ for that route — no new engine, no new permission, no new audit store    │
 * │ and no migration.                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE WAREHOUSE IS THE OPERATOR'S CHOICE. Nothing here derives it from the Zone, City,
 * Governorate, Group, Trip, Driver or Vehicle: `warehouseId` comes from an explicit
 * selection and is sent verbatim. The one automatic path that does infer a warehouse
 * (`assign-warehouse`, which matches a policy) is deliberately NOT called.
 *
 * SUCCESS DOES NOT ASSIGN A GROUP. It invalidates the workspace root like the other
 * mutations, and the server re-derives the Order's blocker on the next read — a
 * warehouse-less Order becomes a Zone/Group exception instead of vanishing. Any Group
 * membership that follows comes from the existing collector, never from this call.
 */
export function useAssignOrderWarehouse() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { orderId: string; warehouseId: string; reason: string }) =>
      preparationService.overrideWarehouse(vars.orderId, {
        warehouse_id: vars.warehouseId,
        reason: vars.reason,
      }),
    onSuccess: invalidate,
  });
}

export function useMoveOrderToSlot() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { assignmentId: string; slotId: string | null; reason?: string }) =>
      distributionWorkspaceService.moveOrderToSlot(vars.assignmentId, vars.slotId, vars.reason),
    onSuccess: invalidate,
  });
}

/**
 * TASK-1-B-ATOMIC-BATCH-MOVE-001 — move several orders into one Group, all or none.
 *
 * Invalidated through the same workspace root as the other mutations, so the source
 * Group, the destination Group, both capacity figures and the KPI totals refresh as one
 * surface. No second synchronisation mechanism, and no Trip is re-synced.
 */
export function useMoveOrdersToSlot() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { assignmentIds: string[]; slotId: string | null; reason?: string }) =>
      distributionWorkspaceService.moveOrdersToSlot(vars.assignmentIds, vars.slotId, vars.reason),
    onSuccess: invalidate,
  });
}

export function useAssignLateOrder() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; orderId: string; reason?: string }) =>
      distributionWorkspaceService.assignLateOrder(vars.windowId, vars.orderId, vars.reason),
    onSuccess: invalidate,
  });
}

// ── Inline Zones-table edits (TASK-DISTRIBUTION-ZONES-TABLE-UX-001) ────────────

/**
 * Change one order's Distribution Zone from the Zones table — the existing
 * Change Zone contract. Invalidates the whole workspace root, so the row, the
 * zone counts, the Groups board and the Map all refresh together (all live under
 * KEYS.all). The backend re-syncs the Group and enforces capacity; a 422 is
 * surfaced verbatim by the caller.
 */
export function useChangeOrderZone() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { assignmentId: string; zoneId: number; reason?: string }) =>
      distributionWorkspaceService.changeOrderZone(vars.assignmentId, vars.zoneId, vars.reason),
    onSuccess: invalidate,
  });
}

/**
 * Patch an order's canonical address (city / governorate) from the Zones table.
 *
 * Reuses the EXISTING Orders quick-update contract (`ordersService.patchOrder` →
 * `PATCH /orders/{id}/quick-update`) — no new endpoint and no second address
 * writer. Governorate/City are written as canonical NAME strings so the Geography
 * binder re-resolves `logistics_city_id` and Distribution re-zones the assignment.
 *
 * Invalidates BOTH roots so the two workspaces stay one source of truth (§5/§14):
 *   - the Distribution workspace (KEYS.all) — Zones table, counts, Groups, Map
 *   - the Orders page (`['company', companyId, ORDERS_KEY]`) — same as usePatchOrder
 */
export function usePatchOrderGeography() {
  const qc = useQueryClient();
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';

  return useMutation({
    mutationFn: (vars: { id: string; data: Record<string, unknown> }) =>
      ordersService.patchOrder(vars.id, vars.data),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEYS.all });
      void qc.invalidateQueries({ queryKey: ['company', companyId, ORDERS_KEY] });
    },
  });
}


// ── Group capacity ───────────────────────────────────────────────────────────

/**
 * Edit a Group's name / maximum orders.
 *
 * Invalidates the whole workspace root like every other mutation here: changing
 * a maximum changes the group card, the capacity figures, the overflow list and
 * the map legend at once.
 */
export function useUpdateGroup() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: { windowId: string; slotId: string; payload: UpdateGroupPayload }) =>
      distributionWorkspaceService.updateGroup(vars.windowId, vars.slotId, vars.payload),
    onSuccess: invalidate,
  });
}

// ── Map ──────────────────────────────────────────────────────────────────────

/** Zones, groups and plotted orders. Fetched only while the Map tab is open. */
export function useDistributionMap(
  windowId: string | undefined,
  warehouseId: string | null = null,
  enabled = true,
) {
  return useQuery({
    queryKey: KEYS.map(windowId ?? '', warehouseId),
    queryFn: () => distributionWorkspaceService.getMap(windowId as string, warehouseId),
    enabled: Boolean(windowId) && enabled,
  });
}

/**
 * TASK-DISTRIBUTION-MAP-EXPLICIT-GEOCODING-GATE-001.
 *
 * Resolve ONE order's location through the EXISTING server-side contract
 * (`POST /orders/{order}/resolve-location`) — invoked ONLY by an explicit user
 * action (a "Resolve location" button), never automatically and never on map mount.
 *
 * A mutation, not a query: it must run on click, exactly once per press, with no
 * `enabled`/mount side effect. On a NEW point (`resolved_from_address`) it invalidates
 * the whole workspace root so the map refetches and the pin appears; every other
 * outcome (already `available`, `address_unavailable`, `geocoding_failed`,
 * `not_configured`) changes no data and is surfaced by the caller. Persistence into the
 * existing `google_maps_lat/lng` + `location_source` columns is the endpoint's; nothing
 * here writes, and opening the map performs none of this.
 */
export function useResolveOrderLocationAction() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (orderId: string) => ordersService.resolveLocation(orderId),
    onSuccess: (result) => {
      if (result.status === 'resolved_from_address') {
        invalidate();
      }
    },
  });
}

// ── Group templates ──────────────────────────────────────────────────────────

/** Company-scoped templates. Fetched only while the Templates tab is open. */
export function useGroupTemplates(enabled = true) {
  return useQuery({
    queryKey: KEYS.templates(),
    queryFn: () => distributionWorkspaceService.getGroupTemplates(),
    enabled,
  });
}

export function useSaveGroupTemplate() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    // One mutation for create and edit: the only difference is whether an id
    // exists, and splitting them would duplicate the invalidation and the error
    // handling for no gain.
    mutationFn: (vars: { templateId?: string; payload: SaveGroupTemplatePayload }) =>
      vars.templateId
        ? distributionWorkspaceService.updateGroupTemplate(vars.templateId, vars.payload)
        : distributionWorkspaceService.createGroupTemplate(vars.payload),
    onSuccess: invalidate,
  });
}

export function useArchiveGroupTemplate() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (templateId: string) =>
      distributionWorkspaceService.archiveGroupTemplate(templateId),
    onSuccess: invalidate,
  });
}

/**
 * Apply a template — creates a Group.
 *
 * Invalidates the root because a new Group changes the Groups tab, the zone
 * board (its zones are now taken), the capacity figures and the map together.
 */
export function useApplyGroupTemplate() {
  const invalidate = useInvalidateWorkspace();

  return useMutation({
    mutationFn: (vars: {
      windowId: string;
      templateId: string;
      payload: ApplyGroupTemplatePayload;
    }) =>
      distributionWorkspaceService.applyGroupTemplate(
        vars.windowId,
        vars.templateId,
        vars.payload,
      ),
    onSuccess: invalidate,
  });
}

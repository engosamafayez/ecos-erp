import { useOrganizationContext } from '@/features/organization/context/organization-context';
import {
  useCurrentDistributionWindow,
  useDistributionOrders,
} from '@/features/logistics/distribution-workspace/hooks/use-distribution-workspace';
import { useLoadingSessionsOverview } from '@/features/operations/loading-os/hooks/use-loading-os';
import { useTripStats } from '@/features/logistics/trips/hooks/use-trips';
import { useShippingOrdersQuery } from '@/features/operations/shipping-orders/hooks/use-shipping-orders';
import { useDriverSettlementBoard } from '@/features/operations/driver-settlement/hooks/use-driver-settlement';
import { useAlerts } from '@/features/logistics/operations/hooks/use-operations';
import type { OperationalAlert } from '@/features/logistics/operations/types/operations';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-001 — Control Tower data layer.
 *
 * Control Tower invents no backend fact and runs no aggregation the underlying
 * modules do not already compute. Every field below is read from an EXISTING
 * canonical hook/endpoint the corresponding workspace already uses for itself;
 * this file only composes those reads into one shape the KPI grid and the
 * Needs Attention panel can render. Where a clean, non-fabricated number is
 * genuinely not available (documented per-field below), the value is `null`
 * and the caller must render a deep-link-only card — never a invented 0.
 */

export type KpiQueryState = {
  /** True while the underlying query is fetching for the first time. */
  isLoading: boolean;
  /** True when the read failed — render an honest "unavailable", never 0. */
  isError: boolean;
  /** The real count, or null when not (yet) available. */
  value: number | null;
};

export function useControlTowerKpis() {
  const { activeWarehouseId, activeCompanyId } = useOrganizationContext();

  // ── Ready for Distribution ──────────────────────────────────────────────
  // Same "current window" resolution + orders read the Distribution Workspace
  // itself uses for its own "Eligible" KPI (distribution-workspace-page.tsx).
  // `orders.length` there IS the eligible-orders count — reused verbatim
  // rather than re-derived. A window that fails to resolve (no active
  // Preparation Wave / tenant) is a real "not available" state, not a zero.
  const distributionWindow = useCurrentDistributionWindow(activeWarehouseId);
  const windowId = distributionWindow.data?.window?.id;
  const windowResolved = distributionWindow.data?.resolution === 'resolved' && Boolean(windowId);
  const distributionOrders = useDistributionOrders(
    windowId,
    activeWarehouseId,
    null,
    null,
    windowResolved,
  );

  const readyForDistribution: KpiQueryState = {
    isLoading: distributionWindow.isLoading || distributionOrders.isLoading,
    isError: distributionWindow.isError || distributionOrders.isError,
    value: windowResolved ? (distributionOrders.data?.length ?? null) : null,
  };

  // ── Groups waiting for Vehicle / Driver — DELIBERATELY NO COUNT ─────────
  // Verified against the backend: `GET .../windows/{w}/slots`
  // (DistributionAggregationService::slotSummaries) returns no vehicle_id,
  // driver_id or assignment-state field — `SlotSummary.status` is a hardcoded
  // 'draft' literal. The ONLY place vehicle/driver assignment is visible is
  // the per-Group `GET .../slots/{slot}/trips` read (useGroupTrips), which the
  // Distribution Workspace itself calls lazily, ONE GROUP AT A TIME, only
  // while that Group's own "Vehicle & Driver" tab is open — there is no bulk
  // equivalent. Computing a cross-group count here would mean firing one
  // request per open Group on every Control Tower load, which is not "reusing
  // the existing hook the way the Groups tab already does" — it is a new,
  // unbounded fan-out. So these two tiles are honest deep-link-only cards.

  // ── Loading in Progress / Ready for Driver Handover ─────────────────────
  // The SAME session-grain read model the Loading OS workspace's own tab
  // strip uses to label its tabs (loading-os-workspace-page.tsx:
  // `useLoadingSessionsOverview(warehouse, undefined, 1)` → `counts`), fetched
  // once and reused for both tiles. `waiting_driver_confirmation` is the exact
  // bucket the task names; `current_actionable` (sessions the warehouse still
  // has an action pending on) is the closest server-authoritative bucket to
  // "Loading in Progress".
  const loadingOverview = useLoadingSessionsOverview(activeWarehouseId, undefined, 1);

  const loadingInProgress: KpiQueryState = {
    isLoading: loadingOverview.isLoading,
    isError: loadingOverview.isError,
    value: loadingOverview.data?.counts.current_actionable ?? null,
  };
  const readyForDriverHandover: KpiQueryState = {
    isLoading: loadingOverview.isLoading,
    isError: loadingOverview.isError,
    value: loadingOverview.data?.counts.waiting_driver_confirmation ?? null,
  };

  // ── Trips Active ─────────────────────────────────────────────────────────
  // `TripStats.on_the_road` is a server-side rollup of
  // dispatched + out_for_delivery + in_progress (see trip.ts) — reused as-is
  // rather than fetching the list and filtering client-side.
  const tripStats = useTripStats(activeCompanyId ?? undefined);
  const tripsActive: KpiQueryState = {
    isLoading: tripStats.isLoading,
    isError: tripStats.isError,
    value: tripStats.data?.on_the_road ?? null,
  };

  // ── Deliveries Failed Today / No Answer / Postponed / Retries Required ──
  // The exact `useShippingOrdersQuery` hook the Shipping Orders page uses,
  // called with per_page=1 and no filters: the backend computes tab counts
  // from the full filtered population regardless of page size or the
  // classification filter (ShippingOrderController::tabCounts, verified
  // server-side), so this is the true global count, not a page-scoped one.
  const shippingOrders = useShippingOrdersQuery({ page: 1, per_page: 1 });
  const shippingCounts = shippingOrders.data?.meta.counts;

  const deliveriesFailedToday: KpiQueryState = {
    isLoading: shippingOrders.isLoading,
    isError: shippingOrders.isError,
    value: shippingCounts?.cancelled ?? null,
  };
  const noAnswer: KpiQueryState = {
    isLoading: shippingOrders.isLoading,
    isError: shippingOrders.isError,
    value: shippingCounts?.no_answer ?? null,
  };
  const postponed: KpiQueryState = {
    isLoading: shippingOrders.isLoading,
    isError: shippingOrders.isError,
    value: shippingCounts?.postponed ?? null,
  };
  // DOCUMENTED CHOICE: "Retries Required" = postponed + no_answer. Both
  // FailureReason values are marked retryable in the backend's catalogue (per
  // task spec); there is no separate canonical "retries" count, so this tile
  // is a client-side SUM of two real, already-fetched server counts rather
  // than a new query or an invented number.
  const retriesRequired: KpiQueryState = {
    isLoading: shippingOrders.isLoading,
    isError: shippingOrders.isError,
    value:
      shippingCounts !== undefined ? shippingCounts.postponed + shippingCounts.no_answer : null,
  };

  // ── Returns Expected / Returns Awaiting Warehouse Receipt — NO COUNT ────
  // Verified: `TripReturn` has no operator-facing list endpoint — only
  // `GET /driver/trips/{tripId}/returns` (driver-scoped). No cross-trip
  // aggregate exists to read, so these stay deep-link-only.

  // ── Drivers Awaiting Settlement ──────────────────────────────────────────
  // `GET /logistics/distribution/driver-settlement?scope=active`
  // (DriverDaySettlementController::index) via the existing
  // useDriverSettlementBoard hook — a real list/index, reused directly.
  const driverSettlement = useDriverSettlementBoard({ scope: 'active' });
  const driversAwaitingSettlement: KpiQueryState = {
    isLoading: driverSettlement.isLoading,
    isError: driverSettlement.isError,
    value: driverSettlement.data
      ? (driverSettlement.data.meta?.total ?? driverSettlement.data.drivers.length)
      : null,
  };

  // ── Cash Handover Pending — NO COUNT ─────────────────────────────────────
  // Verified: `TripCashHandover` has no list endpoint, only per-trip
  // show/confirm. Deep-link-only, same honest treatment as Returns.

  // ── Needs Attention / Critical Operational Blockers ─────────────────────
  // The exact `useAlerts` hook backing the (now-redirected) Alert Center's
  // Live tab — GET-based, already severity-ranked server-side, unpaginated
  // (the full current alert set), so the "critical" count and the rendered
  // list can never disagree with each other.
  const alertsQuery = useAlerts();
  const alerts: OperationalAlert[] = alertsQuery.data ?? [];
  const criticalBlockers: KpiQueryState = {
    isLoading: alertsQuery.isLoading,
    isError: alertsQuery.isError,
    value: alertsQuery.data ? alerts.filter((a) => a.severity === 'critical').length : null,
  };

  return {
    readyForDistribution,
    loadingInProgress,
    readyForDriverHandover,
    tripsActive,
    deliveriesFailedToday,
    noAnswer,
    postponed,
    retriesRequired,
    driversAwaitingSettlement,
    criticalBlockers,
    alerts: {
      items: alerts,
      isLoading: alertsQuery.isLoading,
      isError: alertsQuery.isError,
    },
  };
}

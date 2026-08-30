import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useFormatter } from '@/hooks/use-formatter';

import { DistributionMapTab } from './distribution-map-tab';
import { GroupTripPanel } from './group-trip-panel';
import { GroupVehicleAssignment } from './group-vehicle-assignment';
import { GroupZoneManager } from './group-zone-manager';
import { useGroupTrips } from '../hooks/use-distribution-workspace';
import type { DistributionOrder, SlotSummary, ZoneSummary } from '../types';

/**
 * GROUP DETAIL SECTION — TASK-DISTRIBUTION-PLANNING-FINAL-UI-SYNC-IMPLEMENTATION-001.
 *
 * Rendered INLINE below the Group Cards grid for the selected group — not a drawer,
 * not a new page, no URL change. A tab strip drives the whole detail:
 *
 *   Header
 *   ┌ Map & Group Details ┬ Orders ┬ Zones ┬ Vehicle & Driver ┬ Trip ┐
 *   │  ┌────────────┬───────────┐                                     │
 *   │  │ GROUP MAP  │ DETAILS   │   (60% / 40%, stacked on mobile)    │
 *   │  └────────────┴───────────┘                                     │
 *
 * There is NO Loading tab here — Loading has its own workspace (§18/§19).
 *
 * PRESENTATION + DATA-SCOPING ONLY. The map is the existing `DistributionMapTab`
 * with `focusGroupId`, which filters the payload to THIS group's orders/zones
 * client-side (read-only mount; existing clustering / MapOrderPanel / explicit
 * geocoding gate intact — no new fetch, no backend change). Vehicle / Driver / Trip
 * are read from the CANONICAL `useGroupTrips` query (the persisted Trip's
 * driverVehicleAssignment), never from the assignment form's local state; the assign
 * mutation invalidates that query so the details reflect the saved pairing without a
 * reload. Nothing invents a KPI or business rule.
 */

/** One label → value row in the Group Details panel. */
function DetailRow({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex items-center justify-between gap-3 border-b py-1.5 last:border-b-0">
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="text-end text-sm font-medium tabular-nums">{value}</dd>
    </div>
  );
}

export function GroupDetailSection({
  group,
  windowId,
  warehouseId,
  siblings,
  zones,
  canPlan,
  orders,
  columns,
  rowId,
  ordersLoading,
}: {
  group: SlotSummary;
  windowId: string | undefined;
  warehouseId: string | null;
  /** All groups in the window — the trip panel and zone manager both need the set. */
  siblings: SlotSummary[];
  zones: ZoneSummary[];
  canPlan: boolean;
  orders: DistributionOrder[];
  columns: DataGridColumnDef<DistributionOrder>[];
  rowId: (o: DistributionOrder) => string;
  ordersLoading: boolean;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();

  // Orders belonging to THIS group (by slot membership) — the scoped table source.
  const groupOrders = orders.filter((o) => o.virtual_slot_id === group.slot_id);
  const avgOrderValue = group.orders_count > 0 ? group.total_value / group.orders_count : 0;

  const zoneTitle = group.zone_names.length > 0 ? group.zone_names.join(' & ') : '—';

  const hasMax = group.capacity_orders != null;
  const ordersLabel = `${group.demand_orders} / ${group.capacity_orders ?? '∞'}`;
  const progressPct = hasMax
    ? Math.min(100, Math.round((group.demand_orders / (group.capacity_orders as number)) * 100))
    : null;

  // Vehicle / Driver / Trip — read from the CANONICAL group-trips query
  // (getGroupTrips → the persisted Trip's driverVehicleAssignment), NEVER from the
  // assignment form's local state. `useAssignGroupVehicle` invalidates KEYS.all,
  // under which this query lives, so it refetches on a successful assign and the
  // details reflect the saved pairing without a reload — and again on re-select /
  // refresh, because this is a plain read of the backend. A failed assign persists
  // nothing, so nothing appears. When a group carries more than one Trip we show the
  // one that actually holds the vehicle/driver pairing, not blindly the first.
  const tripsQuery = useGroupTrips(windowId, group.slot_id);
  const trips = tripsQuery.data?.trips ?? [];
  const trip =
    trips.find((tr) => tr.driver_vehicle_assignment_id !== null) ?? trips[0] ?? null;
  const notAssigned = t(($) => $.distributionWorkspace.groups.notAssigned);
  // A read failure is NOT "no assignment": show an em dash (unknown) when the trips
  // query errored, and "Not assigned" only when the canonical read succeeded and no
  // pairing exists. Never interpret an error as an absent vehicle/driver.
  const tripUnknown = tripsQuery.isError;
  const vehicleLabel = tripUnknown
    ? '—'
    : trip?.vehicle
      ? (trip.vehicle.plate_number ?? trip.vehicle.name ?? '—')
      : notAssigned;
  const driverLabel = tripUnknown ? '—' : (trip?.driver?.full_name ?? notAssigned);
  const tripLabel = tripUnknown ? '—' : trip ? trip.status : '—';

  return (
    <Card className="mt-6 space-y-4 p-4" data-testid={`group-detail-${group.code}`}>
      {/* ── Header ─────────────────────────────────────────────────────────── */}
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="text-lg font-semibold">{group.code}</h3>
        {group.name ? (
          <span className="text-sm text-muted-foreground">{group.name}</span>
        ) : null}
        <span className="text-sm text-muted-foreground">{zoneTitle}</span>
        <Badge variant="secondary" className="capitalize">
          {group.status}
        </Badge>
      </div>

      {/* ── Detail tabs (no Loading tab — Loading has its own workspace) ────── */}
      <Tabs defaultValue="map">
        <TabsList className="flex-wrap">
          <TabsTrigger value="map">
            {t(($) => $.distributionWorkspace.phase1.tabMapDetails)}
          </TabsTrigger>
          <TabsTrigger value="orders">
            {t(($) => $.distributionWorkspace.phase1.tabOrders)}
          </TabsTrigger>
          <TabsTrigger value="zones">
            {t(($) => $.distributionWorkspace.phase1.tabZones)}
          </TabsTrigger>
          <TabsTrigger value="vehicle">
            {t(($) => $.distributionWorkspace.phase1.tabVehicle)}
          </TabsTrigger>
          <TabsTrigger value="trip">
            {t(($) => $.distributionWorkspace.phase1.tabTrip)}
          </TabsTrigger>
        </TabsList>

        {/* ── Tab 1 · Map (~60%) | Group Details (~40%) ────────────────────── */}
        <TabsContent value="map" className="mt-3">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-[11fr_9fr] lg:grid-cols-[3fr_2fr]">
            {/* Map column — existing map, scoped to THIS group (read-only). */}
            <div className="min-w-0">
              {windowId ? (
                <DistributionMapTab
                  key={group.slot_id}
                  windowId={windowId}
                  warehouseId={warehouseId}
                  active
                  focusGroupId={group.slot_id}
                  showToolbar={false}
                />
              ) : null}
            </div>

            {/* Details column — same height as the map area, internal scroll. */}
            <div className="min-w-0">
              <Card className="flex h-full flex-col p-4">
                <h4 className="mb-2 shrink-0 text-sm font-semibold">
                  {t(($) => $.distributionWorkspace.phase1.detailsTitle)}
                </h4>
                <dl className="min-h-0 flex-1 overflow-y-auto pe-1">
                  <DetailRow label={t(($) => $.common.zone)} value={zoneTitle} />
                  <DetailRow
                    label={t(($) => $.distributionWorkspace.phase1.cardValue)}
                    value={money(group.total_value)}
                  />
                  <DetailRow
                    label={t(($) => $.distributionWorkspace.metrics.orders)}
                    value={ordersLabel}
                  />
                  <DetailRow
                    label={t(($) => $.distributionWorkspace.phase1.progressOrders)}
                    value={progressPct === null ? '—' : `${progressPct}%`}
                  />
                  <DetailRow
                    label={t(($) => $.distributionWorkspace.phase1.avgOrderValue)}
                    value={money(avgOrderValue)}
                  />
                  {/* Not in the canonical payload — shown as unavailable, never invented. */}
                  <DetailRow
                    label={t(($) => $.distributionWorkspace.phase1.estimatedDistance)}
                    value="—"
                  />
                  <DetailRow label={t(($) => $.common.vehicle)} value={vehicleLabel} />
                  <DetailRow label={t(($) => $.common.driver)} value={driverLabel} />
                  <DetailRow
                    label={t(($) => $.distributionWorkspace.phase1.tabTrip)}
                    value={tripLabel}
                  />
                </dl>
                {group.is_over_capacity ? (
                  <p className="mt-2 shrink-0 text-xs font-medium text-destructive">
                    {t(($) => $.distributionWorkspace.settings.overCapacity, {
                      count: group.overflow_orders,
                    })}
                  </p>
                ) : null}
              </Card>
            </div>
          </div>
        </TabsContent>

        {/* ── Tab 2 · Orders in this Group ─────────────────────────────────── */}
        <TabsContent value="orders" className="mt-3">
          <h4 className="mb-2 text-sm font-semibold">
            {t(($) => $.distributionWorkspace.phase1.ordersInGroup, { count: groupOrders.length })}
          </h4>
          <UniversalDataGrid
            data={groupOrders}
            columns={columns}
            rowId={rowId}
            loading={ordersLoading}
            emptyState={
              <div className="p-6 text-center text-sm text-muted-foreground">
                {t(($) => $.distributionWorkspace.groups.ordersEmpty)}
              </div>
            }
          />
        </TabsContent>

        {/* ── Tab 3 · Zones ────────────────────────────────────────────────── */}
        <TabsContent value="zones" className="mt-3">
          {windowId ? (
            <GroupZoneManager
              windowId={windowId}
              group={group}
              allGroups={siblings}
              zones={zones}
              canPlan={canPlan}
            />
          ) : null}
        </TabsContent>

        {/* ── Tab 4 · Vehicle & Driver ─────────────────────────────────────── */}
        <TabsContent value="vehicle" className="mt-3">
          {windowId ? (
            <GroupVehicleAssignment windowId={windowId} slotId={group.slot_id} canPlan={canPlan} />
          ) : null}
        </TabsContent>

        {/* ── Tab 5 · Trip ─────────────────────────────────────────────────── */}
        <TabsContent value="trip" className="mt-3">
          {windowId ? (
            <GroupTripPanel windowId={windowId} group={group} siblings={siblings} open />
          ) : null}
        </TabsContent>
      </Tabs>
    </Card>
  );
}

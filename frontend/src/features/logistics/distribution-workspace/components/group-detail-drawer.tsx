import { useTranslation } from 'react-i18next';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useFormatter } from '@/hooks/use-formatter';

import { GroupLoadingPreparation } from './group-loading-preparation';
import { GroupTripPanel } from './group-trip-panel';
import { GroupLoadingExecution } from './group-loading-execution';
import { GroupVehicleAssignment } from './group-vehicle-assignment';
import { GroupZoneManager } from './group-zone-manager';
import type {
  DistributionOrder,
  PreparationWaveCycle,
  SlotSummary,
  ZoneSummary,
} from '../types';

/**
 * GROUP DETAIL DRAWER — TASK-DISTRIBUTION-PLANNING-WORKSPACE-PHASE-1.
 *
 * A PRESENTATION shell only. It re-hosts the already-certified per-group panels
 * (zone manager, orders grid, loading preparation, trip reconciliation, vehicle +
 * driver assignment, loading execution) inside the enterprise overlay Detail
 * Drawer instead of the previous long inline expansion on the board. Not one line
 * of the panels' logic is duplicated or changed here — each is mounted with the
 * exact same props the board used to pass, and Radix `TabsContent` keeps the
 * heavier panels un-mounted until their tab is opened, so nothing fetches on open
 * beyond the Overview the operator is already looking at.
 */

/** Render a UTC boundary in the wave's operational timezone (display only). */
function cycleTime(iso: string | null | undefined, timezone: string | null): string {
  if (!iso) return '—';
  const parsed = new Date(iso.includes('T') ? iso : iso.replace(' ', 'T') + 'Z');
  if (Number.isNaN(parsed.getTime())) return iso;
  try {
    return new Intl.DateTimeFormat('en-GB', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: timezone ?? 'UTC',
    }).format(parsed);
  } catch {
    return parsed.toISOString().slice(11, 16);
  }
}

function Field({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="font-semibold tabular-nums">{value}</dd>
    </div>
  );
}

export function GroupDetailDrawer({
  group,
  windowId,
  siblings,
  zones,
  canPlan,
  warehouseNames,
  orders,
  columns,
  rowId,
  ordersLoading,
  wave,
  open,
  onOpenChange,
}: {
  group: SlotSummary | null;
  windowId: string | undefined;
  /** All groups in the window — the trip panel and zone manager both need the set. */
  siblings: SlotSummary[];
  zones: ZoneSummary[];
  canPlan: boolean;
  warehouseNames: Record<string, string>;
  orders: DistributionOrder[];
  columns: DataGridColumnDef<DistributionOrder>[];
  rowId: (o: DistributionOrder) => string;
  ordersLoading: boolean;
  wave: PreparationWaveCycle | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();

  if (!group) return null;

  const title = group.name ? `${group.code} — ${group.name}` : group.code;
  const maxLabel =
    group.capacity_orders ?? t(($) => $.distributionWorkspace.capacity.noMaximum);
  const subtitle = t(($) => $.distributionWorkspace.phase1.groupDrawerSubtitle, {
    current: group.demand_orders,
    max: maxLabel,
  });

  const groupOrders = orders.filter((o) => o.virtual_slot_id === group.slot_id);

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      description={subtitle}
      size="xl"
    >
      <Tabs defaultValue="overview" className="w-full">
        <TabsList className="flex-wrap">
          <TabsTrigger value="overview">
            {t(($) => $.distributionWorkspace.phase1.tabOverview)}
          </TabsTrigger>
          <TabsTrigger value="orders">
            {t(($) => $.distributionWorkspace.phase1.tabOrders)}
          </TabsTrigger>
          <TabsTrigger value="zones">
            {t(($) => $.distributionWorkspace.phase1.tabZones)}
          </TabsTrigger>
          <TabsTrigger value="wave">
            {t(($) => $.distributionWorkspace.phase1.tabWave)}
          </TabsTrigger>
          <TabsTrigger value="capacity">
            {t(($) => $.distributionWorkspace.phase1.tabCapacity)}
          </TabsTrigger>
          <TabsTrigger value="trip">
            {t(($) => $.distributionWorkspace.phase1.tabTrip)}
          </TabsTrigger>
          <TabsTrigger value="vehicle">
            {t(($) => $.distributionWorkspace.phase1.tabVehicle)}
          </TabsTrigger>
          <TabsTrigger value="loading">
            {t(($) => $.distributionWorkspace.phase1.tabLoading)}
          </TabsTrigger>
        </TabsList>

        {/* ── Overview ─────────────────────────────────────────────────────── */}
        <TabsContent value="overview" className="mt-3 space-y-3">
          <div className="flex flex-wrap gap-1.5">
            {group.zone_names.map((zoneName) => (
              <Badge key={zoneName} variant="outline">
                {zoneName}
              </Badge>
            ))}
          </div>
          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-6">
            <Field label={t(($) => $.distributionWorkspace.metrics.zones)} value={group.zones_count} />
            <Field label={t(($) => $.distributionWorkspace.metrics.orders)} value={group.orders_count} />
            <Field label={t(($) => $.distributionWorkspace.metrics.products)} value={group.products_count} />
            <Field label={t(($) => $.distributionWorkspace.metrics.orderValue)} value={money(group.total_value)} />
            <Field label={t(($) => $.distributionWorkspace.metrics.paid)} value={group.paid_orders} />
            <Field label={t(($) => $.distributionWorkspace.metrics.unpaidCod)} value={group.unpaid_orders} />
          </dl>
        </TabsContent>

        {/* ── Orders — the SAME grid + columns as the pool ─────────────────── */}
        <TabsContent value="orders" className="mt-3">
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

        {/* ── Zones ────────────────────────────────────────────────────────── */}
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

        {/* ── Wave (cycle context, read-only) ──────────────────────────────── */}
        <TabsContent value="wave" className="mt-3">
          {wave ? (
            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-5">
              <Field label={t(($) => $.distributionWorkspace.cycle.wave)} value={wave.wave_number} />
              <Field label={t(($) => $.distributionWorkspace.cycle.start)} value={cycleTime(wave.starts_at, wave.timezone)} />
              <Field label={t(($) => $.distributionWorkspace.cycle.cutoff)} value={cycleTime(wave.cutoff_at, wave.timezone)} />
              <Field label={t(($) => $.distributionWorkspace.cycle.end)} value={cycleTime(wave.ends_at, wave.timezone)} />
              <Field label={t(($) => $.distributionWorkspace.cycle.timezone)} value={wave.timezone ?? '—'} />
            </dl>
          ) : (
            <p className="text-sm text-amber-700 dark:text-amber-400">
              {t(($) => $.distributionWorkspace.cycle.noWave)}
            </p>
          )}
        </TabsContent>

        {/* ── Capacity (triplet + overflow status, read-only) ──────────────── */}
        <TabsContent value="capacity" className="mt-3 space-y-2">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {t(($) => $.distributionWorkspace.capacity.title)}
          </p>
          <dl className="grid grid-cols-3 gap-3 rounded-md border bg-muted/30 p-3">
            <Field label={t(($) => $.distributionWorkspace.capacity.current)} value={group.demand_orders} />
            <Field
              label={t(($) => $.distributionWorkspace.capacity.maximum)}
              value={group.capacity_orders ?? t(($) => $.distributionWorkspace.capacity.noMaximum)}
            />
            <Field
              label={t(($) => $.distributionWorkspace.capacity.remaining)}
              value={group.remaining_orders ?? t(($) => $.distributionWorkspace.capacity.unbounded)}
            />
          </dl>
          {group.is_over_capacity ? (
            <p className="text-xs font-medium text-destructive">
              {t(($) => $.distributionWorkspace.settings.overCapacity, { count: group.overflow_orders })}
            </p>
          ) : null}
        </TabsContent>

        {/* ── Trip ─────────────────────────────────────────────────────────── */}
        <TabsContent value="trip" className="mt-3">
          {windowId ? (
            <GroupTripPanel windowId={windowId} group={group} siblings={siblings} open />
          ) : null}
        </TabsContent>

        {/* ── Vehicle & Driver ─────────────────────────────────────────────── */}
        <TabsContent value="vehicle" className="mt-3">
          {windowId ? (
            <GroupVehicleAssignment windowId={windowId} slotId={group.slot_id} canPlan={canPlan} />
          ) : null}
        </TabsContent>

        {/* ── Loading (preparation + execution) ────────────────────────────── */}
        <TabsContent value="loading" className="mt-3 space-y-3">
          {windowId ? (
            <>
              <GroupLoadingPreparation
                windowId={windowId}
                group={group}
                warehouseNames={warehouseNames}
                open
              />
              <div className="border-t pt-3">
                <GroupLoadingExecution windowId={windowId} group={group} canPlan={canPlan} />
              </div>
            </>
          ) : null}
        </TabsContent>
      </Tabs>
    </PageDrawer>
  );
}

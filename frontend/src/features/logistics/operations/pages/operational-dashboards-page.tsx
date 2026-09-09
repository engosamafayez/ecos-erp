import { useTranslation } from 'react-i18next';
import { Activity, Gauge, Layers, Truck, Users } from 'lucide-react';

import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import {
  useCapacityDashboard,
  useDispatchDashboard,
  useDriverDashboard,
  useFleetDashboard,
  useKpiDashboard,
} from '../hooks/use-operations-analytics';

function pct(value: number | null | undefined, noData: string): string {
  if (value === null || value === undefined) return noData;
  return `${Math.round(value * 100)}%`;
}

function Card({
  label,
  value,
  tone,
}: {
  label: string;
  value: string | number;
  tone?: 'good' | 'warn' | 'bad';
}) {
  const toneClass =
    tone === 'bad'
      ? 'text-destructive'
      : tone === 'warn'
        ? 'text-amber-600'
        : tone === 'good'
          ? 'text-emerald-600'
          : '';

  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-1 text-2xl tabular-nums ${toneClass}`}>{value}</p>
    </div>
  );
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-3 text-sm font-medium">{title}</h3>
      {children}
    </div>
  );
}

/**
 * A utilisation bar with the snapshot caveat baked in — the figure is
 * "in use right now ÷ available right now", never a projection.
 */
function UtilisationBar({ value }: { value: number | null }) {
  const { t } = useTranslation('logistics');

  if (value === null) {
    return (
      <p className="text-sm text-muted-foreground">
        {t(($) => $.operations.operationalDashboards.noAssignableResources)}
      </p>
    );
  }

  const capped = Math.min(1, Math.max(0, value));

  return (
    <div>
      <div className="mb-1 flex items-center justify-between text-xs">
        <span className="text-muted-foreground">{t(($) => $.operations.operationalDashboards.inUseNow)}</span>
        <span className="tabular-nums">{Math.round(value * 100)}%</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-muted">
        <div
          className={`h-full ${capped >= 0.85 ? 'bg-amber-500' : 'bg-emerald-600'}`}
          style={{ width: `${capped * 100}%` }}
        />
      </div>
    </div>
  );
}

function FleetTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useFleetDashboard();

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-4">
        <Card label={t(($) => $.operations.operationalDashboards.fleet.totalVehicles)} value={data.total_vehicles} />
        <Card
          label={t(($) => $.operations.operationalDashboards.fleet.assignable)}
          value={data.assignable}
          tone="good"
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.fleet.unfit)}
          value={data.unfit}
          tone={data.unfit > 0 ? 'warn' : undefined}
        />
        <Card label={t(($) => $.operations.operationalDashboards.inUseNow)} value={data.in_use_now} />
      </div>

      <Panel title={t(($) => $.operations.operationalDashboards.panel.fleetUtilisation)}>
        <UtilisationBar value={data.utilisation_now} />
        <p className="mt-2 text-xs text-muted-foreground">
          {t(($) => $.operations.operationalDashboards.fleet.idleNote, { count: data.idle_assignable })}
        </p>
      </Panel>

      {/* BO-1: an idle vehicle nobody noticed is pure loss. Named, not counted. */}
      <Panel
        title={t(($) => $.operations.operationalDashboards.fleet.idleTitle, { count: data.idle_vehicles.length })}
      >
        {data.idle_vehicles.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t(($) => $.operations.operationalDashboards.fleet.allWorking)}
          </p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {data.idle_vehicles.map((v) => (
              <Badge key={v.vehicle_id} variant="outline" className="text-xs">
                {v.plate_number ?? `#${v.vehicle_id}`}
              </Badge>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}

function DriverTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useDriverDashboard();

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-4">
        <Card label={t(($) => $.operations.operationalDashboards.driver.totalDrivers)} value={data.total_drivers} />
        <Card
          label={t(($) => $.operations.operationalDashboards.driver.available)}
          value={data.available}
          tone="good"
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.driver.unavailable)}
          value={data.unavailable}
          tone={data.unavailable > 0 ? 'warn' : undefined}
        />
        <Card label={t(($) => $.operations.operationalDashboards.inUseNow)} value={data.in_use_now} />
      </div>

      <Panel title={t(($) => $.operations.operationalDashboards.panel.driverUtilisation)}>
        <UtilisationBar value={data.utilisation_now} />
        <p className="mt-2 text-xs text-muted-foreground">
          {t(($) => $.operations.operationalDashboards.driver.idleNote, { count: data.idle_available })}
        </p>
      </Panel>

      <Panel
        title={t(($) => $.operations.operationalDashboards.driver.idleTitle, { count: data.idle_drivers.length })}
      >
        {data.idle_drivers.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t(($) => $.operations.operationalDashboards.driver.allWorking)}
          </p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {data.idle_drivers.map((d) => (
              <Badge key={d.driver_id} variant="outline" className="text-xs">
                {d.full_name ?? d.driver_code ?? `#${d.driver_id}`}
              </Badge>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}

function CapacityTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useCapacityDashboard();

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Panel title={t(($) => $.operations.operationalDashboards.panel.slotsToday)}>
        <div className="space-y-1.5 text-xs">
          <Row label={t(($) => $.operations.operationalDashboards.capacity.slots)} value={data.slots.slot_count} />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.averageUtilisation)}
            value={pct(data.slots.avg_utilisation, t(($) => $.common.noDataYet))}
          />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.nearCapacity)}
            value={data.slots.at_warn_threshold}
          />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.exhausted)}
            value={data.slots.exhausted}
          />
        </div>
      </Panel>
      <Panel title={t(($) => $.operations.operationalDashboards.panel.ourReservations)}>
        <div className="space-y-1.5 text-xs">
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.requested)}
            value={data.reservations.requested}
          />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.currentlyHolding)}
            value={data.reservations.currently_holding}
          />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.confirmed)}
            value={data.reservations.confirmed}
          />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.refused)}
            value={data.reservations.refused}
          />
          <Row
            label={t(($) => $.operations.operationalDashboards.capacity.refusalRate)}
            value={pct(data.reservations.refusal_rate, t(($) => $.common.noDataYet))}
          />
        </div>
      </Panel>
    </div>
  );
}

function DispatchTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useDispatchDashboard();

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-4">
        <Card
          label={t(($) => $.operations.operationalDashboards.dispatch.activeSessions)}
          value={data.kpis.sessions_active}
          tone="good"
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.dispatch.confirmed)}
          value={data.kpis.allocations_confirmed}
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.dispatch.confirmationRate)}
          value={pct(data.kpis.confirmation_rate, t(($) => $.common.noDataYet))}
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.dispatch.automaticShare)}
          value={pct(data.kpis.automatic_share, t(($) => $.common.noDataYet))}
        />
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <Panel title={t(($) => $.operations.operationalDashboards.panel.queue)}>
          <div className="space-y-1.5 text-xs">
            <Row label={t(($) => $.operations.operationalDashboards.dispatch.depth)} value={data.queue.depth} />
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.needsAction)}
              value={data.queue.needs_action}
            />
            <Row label={t(($) => $.operations.operationalDashboards.dispatch.stuck)} value={data.queue.stuck} />
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.averageWait)}
              value={
                data.queue.avg_wait_minutes !== null
                  ? t(($) => $.operations.operationalDashboards.minutesValue, {
                      minutes: data.queue.avg_wait_minutes,
                    })
                  : '—'
              }
            />
          </div>
        </Panel>
        <Panel title={t(($) => $.operations.operationalDashboards.panel.cycleTime)}>
          <div className="space-y-1.5 text-xs">
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.averageSession)}
              value={
                data.kpis.avg_session_minutes !== null
                  ? t(($) => $.operations.operationalDashboards.minutesValue, {
                      minutes: data.kpis.avg_session_minutes,
                    })
                  : t(($) => $.common.noDataYet)
              }
            />
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.attempted)}
              value={data.kpis.allocations_attempted}
            />
          </div>
        </Panel>
      </div>
    </div>
  );
}

function KpiTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useKpiDashboard();

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-4">
      {data.is_quiet && (
        <div className="rounded-lg border border-emerald-600/30 bg-emerald-600/5 p-4 text-sm text-emerald-700 dark:text-emerald-400">
          {t(($) => $.operations.operationalDashboards.kpi.quietMessage)}
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-3">
        <Card
          label={t(($) => $.operations.operationalDashboards.kpi.criticalAlerts)}
          value={data.headline.critical_alerts}
          tone={data.headline.critical_alerts > 0 ? 'bad' : undefined}
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.kpi.needsAttention)}
          value={data.headline.open_exceptions}
          tone={data.headline.open_exceptions > 0 ? 'warn' : undefined}
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.kpi.unhealthyPools)}
          value={data.headline.unhealthy_pools}
          tone={data.headline.unhealthy_pools > 0 ? 'warn' : undefined}
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.kpi.canFieldToday)}
          value={data.headline.fieldable_units}
          tone="good"
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.kpi.exhaustedSlots)}
          value={data.headline.exhausted_capacity_slots}
          tone={data.headline.exhausted_capacity_slots > 0 ? 'warn' : undefined}
        />
        <Card
          label={t(($) => $.operations.operationalDashboards.kpi.overdueEscalations)}
          value={data.headline.overdue_escalations}
          tone={data.headline.overdue_escalations > 0 ? 'bad' : undefined}
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Panel title={t(($) => $.operations.operationalDashboards.panel.pools)}>
          <div className="space-y-1.5 text-xs">
            <Row label={t(($) => $.operations.operationalDashboards.pools.total)} value={data.pools.total} />
            <Row
              label={t(($) => $.operations.operationalDashboards.pools.unhealthy)}
              value={data.pools.unhealthy}
            />
            <Row
              label={t(($) => $.operations.operationalDashboards.pools.availableVehicles)}
              value={data.pools.available_vehicles}
            />
            <Row
              label={t(($) => $.operations.operationalDashboards.pools.availableDrivers)}
              value={data.pools.available_drivers}
            />
          </div>
        </Panel>
        <Panel title={t(($) => $.operations.operationalDashboards.panel.dispatch)}>
          <div className="space-y-1.5 text-xs">
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.activeSessions)}
              value={data.dispatch.sessions_active}
            />
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.confirmed)}
              value={data.dispatch.allocations_confirmed}
            />
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.confirmationRate)}
              value={pct(data.dispatch.confirmation_rate, t(($) => $.common.noDataYet))}
            />
            <Row
              label={t(($) => $.operations.operationalDashboards.dispatch.automaticShare)}
              value={pct(data.dispatch.automatic_share, t(($) => $.common.noDataYet))}
            />
          </div>
        </Panel>
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-muted-foreground">{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

/**
 * Operational Dashboards.
 *
 * Five read-only views assembled server-side from the owning modules. Every
 * utilisation figure is a snapshot; nothing here forecasts.
 */
export function OperationalDashboardsPage() {
  const { t } = useTranslation('logistics');
  const { refetch, isFetching } = useKpiDashboard();

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[
          { label: t(($) => $.operations.operationalDashboards.breadcrumbRoot) },
          { label: t(($) => $.operations.operationalDashboards.breadcrumbSection) },
        ]}
        title={t(($) => $.operations.operationalDashboards.title)}
        description={t(($) => $.operations.operationalDashboards.description)}
        metrics={[]}
      />

      <WorkspacePage
        toolbar={
          <div className="px-4 sm:px-6">
            <SmartToolbar onRefresh={() => refetch()} isFetching={isFetching} />
          </div>
        }
      >
        <div className="px-4 pb-6 sm:px-6">
          <Tabs defaultValue="kpi" className="w-full">
            <TabsList>
              <TabsTrigger value="kpi">
                <Activity className="me-1 size-3.5" />
                {t(($) => $.operations.operationalDashboards.tabKpi)}
              </TabsTrigger>
              <TabsTrigger value="fleet">
                <Truck className="me-1 size-3.5" />
                {t(($) => $.operations.operationalDashboards.tabFleet)}
              </TabsTrigger>
              <TabsTrigger value="drivers">
                <Users className="me-1 size-3.5" />
                {t(($) => $.operations.operationalDashboards.tabDrivers)}
              </TabsTrigger>
              <TabsTrigger value="capacity">
                <Gauge className="me-1 size-3.5" />
                {t(($) => $.operations.operationalDashboards.tabCapacity)}
              </TabsTrigger>
              <TabsTrigger value="dispatch">
                <Layers className="me-1 size-3.5" />
                {t(($) => $.operations.operationalDashboards.tabDispatch)}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="kpi" className="pt-4">
              <KpiTab />
            </TabsContent>
            <TabsContent value="fleet" className="pt-4">
              <FleetTab />
            </TabsContent>
            <TabsContent value="drivers" className="pt-4">
              <DriverTab />
            </TabsContent>
            <TabsContent value="capacity" className="pt-4">
              <CapacityTab />
            </TabsContent>
            <TabsContent value="dispatch" className="pt-4">
              <DispatchTab />
            </TabsContent>
          </Tabs>
        </div>
      </WorkspacePage>
    </>
  );
}
